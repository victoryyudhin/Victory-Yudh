<?php
declare(strict_types=1);

final class TournamentService
{
    public static function sizeForMode(string $mode): int
    {
        return match ($mode) {
            'solo' => 1,
            'duo' => 2,
            'squad' => 4,
            default => throw new InvalidArgumentException('Choose Solo, Duo, or Squad.'),
        };
    }

    public static function register(int $userId, int $tournamentId, ?int $teamId): int
    {
        $db = Database::connection();
        return WalletService::atomic($db, function (PDO $db) use ($userId, $tournamentId, $teamId): int {
            $stmt = $db->prepare('SELECT * FROM tournaments WHERE id=? FOR UPDATE');
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch();
            if (!$tournament) {
                throw new DomainException('Tournament not found.');
            }
            if ($tournament['status'] !== 'open' || strtotime((string) $tournament['registration_deadline'] . ' UTC') <= time()) {
                throw new DomainException('Registration is closed for this tournament.');
            }
            $requiredSize = self::sizeForMode((string) $tournament['mode']);
            if ((int) $tournament['team_size'] !== $requiredSize) {
                throw new DomainException('This tournament has an invalid team-size configuration.');
            }

            if ($requiredSize === 1) {
                if ($teamId !== null) {
                    throw new DomainException('Solo registration does not use a team.');
                }
                $members = [$userId];
            } else {
                if (!$teamId || $teamId < 1) {
                    throw new DomainException('Create or select a team before registering.');
                }
                $stmt = $db->prepare('SELECT id, captain_id FROM teams WHERE id=? FOR UPDATE');
                $stmt->execute([$teamId]);
                $team = $stmt->fetch();
                if (!$team || (int) $team['captain_id'] !== $userId) {
                    throw new DomainException('Only the team captain can register this team.');
                }
                $stmt = $db->prepare('SELECT user_id FROM team_members WHERE team_id=? ORDER BY joined_at, user_id');
                $stmt->execute([$teamId]);
                $members = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                if (count($members) !== $requiredSize || !in_array($userId, $members, true)) {
                    throw new DomainException('Your team must have exactly ' . $requiredSize . ' active members.');
                }
            }

            $placeholders = implode(',', array_fill(0, count($members), '?'));
            $params = array_merge([(int) $tournament['id']], $members);
            $stmt = $db->prepare(
                "SELECT rm.user_id FROM registration_members rm
                 JOIN registrations r ON r.id=rm.registration_id
                 WHERE rm.tournament_id=? AND rm.user_id IN ($placeholders) AND r.status='confirmed'
                 LIMIT 1"
            );
            $stmt->execute($params);
            if ($stmt->fetchColumn()) {
                throw new DomainException('A player on this entry has already joined this tournament.');
            }

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM registration_members rm JOIN registrations r ON r.id=rm.registration_id
                 WHERE rm.tournament_id=? AND r.status='confirmed'"
            );
            $stmt->execute([$tournamentId]);
            $occupied = (int) $stmt->fetchColumn();
            if ($occupied + count($members) > (int) $tournament['participant_limit']) {
                throw new DomainException('There are not enough open participant slots for this entry.');
            }

            $fee = (int) $tournament['entry_fee_minor'];
            $idem = 'entry:' . $tournamentId . ':' . $userId;
            if ($fee > 0) {
                WalletService::post(
                    $db, $userId, 'entry_fee', $fee, -$fee, 0, $idem,
                    'tournament', $tournamentId, 'Tournament entry fee'
                );
            }
            $stmt = $db->prepare(
                'INSERT INTO registrations (tournament_id, captain_id, team_id, entry_fee_minor, idempotency_key)
                 VALUES (?,?,?,?,?)'
            );
            $stmt->execute([$tournamentId, $userId, $teamId, $fee, $idem]);
            $registrationId = (int) $db->lastInsertId();
            $stmt = $db->prepare(
                'INSERT INTO registration_members (registration_id, tournament_id, user_id) VALUES (?,?,?)'
            );
            foreach ($members as $memberId) {
                $stmt->execute([$registrationId, $tournamentId, $memberId]);
            }
            $stmt = $db->prepare('SELECT title FROM tournaments WHERE id=?');
            $stmt->execute([$tournamentId]);
            $title = (string) $stmt->fetchColumn();
            foreach ($members as $memberId) {
                notify_user(
                    $memberId,
                    'registration',
                    'Registration confirmed',
                    'Your entry for ' . $title . ' is confirmed.',
                    'index.php?page=tournament&id=' . $tournamentId
                );
            }
            return $registrationId;
        });
    }

    public static function refundRegistration(int $registrationId, int $adminId): bool
    {
        $db = Database::connection();
        return WalletService::atomic($db, function (PDO $db) use ($registrationId, $adminId): bool {
            $stmt = $db->prepare(
                'SELECT r.*, t.title, t.status AS tournament_status
                 FROM registrations r JOIN tournaments t ON t.id=r.tournament_id
                 WHERE r.id=? FOR UPDATE'
            );
            $stmt->execute([$registrationId]);
            $registration = $stmt->fetch();
            if (!$registration) {
                throw new DomainException('Registration not found.');
            }
            if ($registration['status'] === 'refunded') {
                return false;
            }
            if ($registration['status'] !== 'confirmed') {
                return false;
            }
            if ($registration['tournament_status'] !== 'cancelled') {
                throw new DomainException('Cancel the tournament before issuing registration refunds.');
            }
            $fee = (int) $registration['entry_fee_minor'];
            if ($fee > 0) {
                WalletService::post(
                    $db,
                    (int) $registration['captain_id'],
                    'entry_refund',
                    $fee,
                    $fee,
                    0,
                    'registration-refund:' . $registrationId,
                    'registration',
                    $registrationId,
                    'Refund for cancelled tournament'
                );
            }
            $db->prepare("UPDATE registrations SET status='refunded', cancelled_at=NOW() WHERE id=?")
                ->execute([$registrationId]);
            $stmt = $db->prepare('SELECT user_id FROM registration_members WHERE registration_id=?');
            $stmt->execute([$registrationId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
                notify_user(
                    (int) $memberId,
                    'refund',
                    'Tournament entry refunded',
                    $registration['title'] . ' was cancelled. Your entry fee has been returned to the captain’s wallet.',
                    'index.php?page=wallet'
                );
            }
            audit_log($adminId, 'registration.refunded', 'registration', $registrationId, ['status' => 'confirmed'], ['status' => 'refunded']);
            return true;
        });
    }

    public static function isParticipant(int $userId, int $tournamentId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT r.id AS registration_id, r.team_id, r.status, r.captain_id
             FROM registration_members rm JOIN registrations r ON r.id=rm.registration_id
             WHERE rm.user_id=? AND rm.tournament_id=? AND r.status='confirmed' LIMIT 1"
        );
        $stmt->execute([$userId, $tournamentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function occupiedSlots(int $tournamentId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM registration_members rm JOIN registrations r ON r.id=rm.registration_id
             WHERE rm.tournament_id=? AND r.status='confirmed'"
        );
        $stmt->execute([$tournamentId]);
        return (int) $stmt->fetchColumn();
    }
}

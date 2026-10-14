<?php
declare(strict_types=1);

final class ScoringService
{
    private const ALLOWED_TIE_BREAKERS = [
        'total_points', 'total_kills', 'placement_points', 'best_rank', 'registration_time',
    ];

    public static function saveRules(int $tournamentId, int $pointsPerKill, array $placementPoints, array $tieBreakers, int $adminId): void
    {
        if ($pointsPerKill < 0 || $pointsPerKill > 100) {
            throw new InvalidArgumentException('Points per kill must be between 0 and 100.');
        }
        $placementPoints = [0 => 0] + $placementPoints;
        foreach ($placementPoints as $rank => $points) {
            if (!is_numeric($rank) || (int) $rank < 0 || (int) $rank > 1000 || !is_int($points) || $points < 0 || $points > 10000) {
                throw new InvalidArgumentException('Placement ranks and points are outside the allowed range.');
            }
        }
        $tieBreakers = self::validateTieBreakers($tieBreakers);
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use ($tournamentId, $pointsPerKill, $placementPoints, $tieBreakers, $adminId): void {
            $stmt = $db->prepare('SELECT id FROM tournaments WHERE id=? FOR UPDATE');
            $stmt->execute([$tournamentId]);
            if (!$stmt->fetchColumn()) {
                throw new DomainException('Tournament not found.');
            }
            $stmt = $db->prepare('SELECT placement_rank, placement_points, points_per_kill, active FROM scoring_rules WHERE tournament_id=?');
            $stmt->execute([$tournamentId]);
            $before = $stmt->fetchAll();
            $encodedTieBreakers = json_encode($tieBreakers, JSON_THROW_ON_ERROR);
            $db->prepare('UPDATE scoring_rules SET active=0 WHERE tournament_id=?')->execute([$tournamentId]);
            $upsert = $db->prepare(
                'INSERT INTO scoring_rules (tournament_id, placement_rank, placement_points, points_per_kill, active, tie_breakers)
                 VALUES (?,?,?,?,1,?)
                 ON DUPLICATE KEY UPDATE placement_points=VALUES(placement_points),
                   points_per_kill=VALUES(points_per_kill), active=1, tie_breakers=VALUES(tie_breakers)'
            );
            foreach ($placementPoints as $rank => $points) {
                $upsert->execute([$tournamentId, (int) $rank, $points, $pointsPerKill, $encodedTieBreakers]);
            }
            $db->prepare('UPDATE tournaments SET tie_breakers=? WHERE id=?')->execute([$encodedTieBreakers, $tournamentId]);
            self::recalculateWithinTransaction($db, $tournamentId, $adminId);
            $db->prepare(
                "UPDATE tournaments SET results_status='provisional', status=IF(status='completed','live',status), results_published_at=NULL
                 WHERE id=? AND results_status='final'"
            )->execute([$tournamentId]);
            audit_log($adminId, 'scoring.rules_updated', 'tournament', $tournamentId, ['rules' => $before], [
                'rules' => array_map(fn($rank, $points) => [
                    'rank' => (int) $rank, 'placement_points' => $points, 'points_per_kill' => $pointsPerKill,
                ], array_keys($placementPoints), array_values($placementPoints)),
                'tie_breakers' => $tieBreakers,
            ]);
        });
    }

    public static function saveResult(
        int $tournamentId,
        int $matchNumber,
        int $registrationId,
        int $placementRank,
        int $kills,
        int $bonusPoints,
        int $penaltyPoints,
        int $adminId
    ): void {
        if ($matchNumber < 1 || $matchNumber > 100 || $registrationId < 1 ||
            $placementRank < 1 || $placementRank > 1000 || $kills < 0 || $kills > 1000 ||
            $bonusPoints < 0 || $bonusPoints > 10000 || $penaltyPoints < 0 || $penaltyPoints > 10000) {
            throw new InvalidArgumentException('Match result values are outside the allowed range.');
        }
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use (
            $tournamentId, $matchNumber, $registrationId, $placementRank, $kills,
            $bonusPoints, $penaltyPoints, $adminId
        ): void {
            $stmt = $db->prepare('SELECT id, results_status FROM tournaments WHERE id=? FOR UPDATE');
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch();
            if (!$tournament) {
                throw new DomainException('Tournament not found.');
            }
            $stmt = $db->prepare('SELECT id FROM registrations WHERE id=? AND tournament_id=? AND status=\'confirmed\'');
            $stmt->execute([$registrationId, $tournamentId]);
            if (!$stmt->fetchColumn()) {
                throw new DomainException('Choose an active registration from this tournament.');
            }
            $stmt = $db->prepare(
                'INSERT INTO matches (tournament_id, match_number, status)
                 VALUES (?,?,\'completed\')
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), status=\'completed\''
            );
            $stmt->execute([$tournamentId, $matchNumber]);
            $matchId = (int) $db->lastInsertId();
            if ($matchId < 1) {
                $stmt = $db->prepare('SELECT id FROM matches WHERE tournament_id=? AND match_number=?');
                $stmt->execute([$tournamentId, $matchNumber]);
                $matchId = (int) $stmt->fetchColumn();
            }
            $stmt = $db->prepare(
                'SELECT placement_points, points_per_kill FROM scoring_rules
                 WHERE tournament_id=? AND placement_rank=? AND active=1'
            );
            $stmt->execute([$tournamentId, $placementRank]);
            $rule = $stmt->fetch();
            if (!$rule) {
                $stmt = $db->prepare(
                    'SELECT placement_points, points_per_kill FROM scoring_rules
                     WHERE tournament_id=? AND placement_rank=0 AND active=1'
                );
                $stmt->execute([$tournamentId]);
                $rule = $stmt->fetch() ?: ['placement_points' => 0, 'points_per_kill' => 0];
            }
            $placementScore = (int) $rule['placement_points'];
            $killScore = $kills * (int) $rule['points_per_kill'];
            $total = $placementScore + $killScore + $bonusPoints - $penaltyPoints;

            $stmt = $db->prepare(
                'SELECT * FROM results WHERE match_id=? AND registration_id=? FOR UPDATE'
            );
            $stmt->execute([$matchId, $registrationId]);
            $before = $stmt->fetch() ?: null;
            $stmt = $db->prepare(
                'INSERT INTO results
                 (tournament_id, match_id, registration_id, placement_rank, kills, bonus_points, penalty_points,
                  placement_points, kill_points, total_points, entered_by, updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE placement_rank=VALUES(placement_rank), kills=VALUES(kills),
                   bonus_points=VALUES(bonus_points), penalty_points=VALUES(penalty_points),
                   placement_points=VALUES(placement_points), kill_points=VALUES(kill_points),
                   total_points=VALUES(total_points), updated_by=VALUES(updated_by)'
            );
            $stmt->execute([
                $tournamentId, $matchId, $registrationId, $placementRank, $kills, $bonusPoints,
                $penaltyPoints, $placementScore, $killScore, $total, $adminId, $adminId,
            ]);
            $stmt = $db->prepare('SELECT id FROM results WHERE match_id=? AND registration_id=?');
            $stmt->execute([$matchId, $registrationId]);
            $resultId = (int) $stmt->fetchColumn();
            audit_log(
                $adminId,
                $before ? 'result.corrected' : 'result.entered',
                'result',
                $resultId,
                $before ?: null,
                [
                    'tournament_id' => $tournamentId,
                    'match_number' => $matchNumber,
                    'registration_id' => $registrationId,
                    'placement_rank' => $placementRank,
                    'kills' => $kills,
                    'placement_points' => $placementScore,
                    'kill_points' => $killScore,
                    'bonus_points' => $bonusPoints,
                    'penalty_points' => $penaltyPoints,
                    'total_points' => $total,
                ]
            );
            if ($tournament['results_status'] === 'final') {
                $db->prepare("UPDATE tournaments SET results_status='provisional', status='live', results_published_at=NULL WHERE id=?")
                    ->execute([$tournamentId]);
            }
            $stmt = $db->prepare('SELECT rm.user_id FROM registration_members rm WHERE rm.registration_id=?');
            $stmt->execute([$registrationId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
                notify_user((int) $memberId, 'results', 'Match result updated', 'A result for Match ' . $matchNumber . ' was entered or corrected.', 'index.php?page=leaderboard&id=' . $tournamentId);
            }
        });
    }

    public static function recalculate(int $tournamentId, int $adminId): int
    {
        $db = Database::connection();
        return WalletService::atomic($db, fn(PDO $db) => self::recalculateWithinTransaction($db, $tournamentId, $adminId));
    }

    private static function recalculateWithinTransaction(PDO $db, int $tournamentId, int $adminId): int
    {
        $stmt = $db->prepare('SELECT id FROM tournaments WHERE id=? FOR UPDATE');
        $stmt->execute([$tournamentId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('Tournament not found.');
        }
        $stmt = $db->prepare(
            'SELECT r.id, r.placement_rank, r.kills, r.bonus_points, r.penalty_points, sr.placement_points, sr.points_per_kill
             FROM results r
             LEFT JOIN scoring_rules sr ON sr.tournament_id=r.tournament_id
               AND sr.placement_rank=IF(EXISTS (
                 SELECT 1 FROM scoring_rules sx WHERE sx.tournament_id=r.tournament_id
                   AND sx.placement_rank=r.placement_rank AND sx.active=1
               ), r.placement_rank, 0) AND sr.active=1
             WHERE r.tournament_id=? FOR UPDATE'
        );
        $stmt->execute([$tournamentId]);
        $rows = $stmt->fetchAll();
        $update = $db->prepare(
            'UPDATE results SET placement_points=?, kill_points=?, total_points=?, updated_by=? WHERE id=?'
        );
        foreach ($rows as $row) {
            $placement = (int) ($row['placement_points'] ?? 0);
            $killPoints = (int) $row['kills'] * (int) ($row['points_per_kill'] ?? 0);
            $total = $placement + $killPoints + (int) $row['bonus_points'] - (int) $row['penalty_points'];
            $update->execute([$placement, $killPoints, $total, $adminId, $row['id']]);
        }
        audit_log($adminId, 'results.recalculated', 'tournament', $tournamentId, null, ['result_count' => count($rows)]);
        return count($rows);
    }

    public static function publish(int $tournamentId, string $status, int $adminId): void
    {
        if (!in_array($status, ['provisional', 'final'], true)) {
            throw new InvalidArgumentException('Choose provisional or final results.');
        }
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use ($tournamentId, $status, $adminId): void {
            $stmt = $db->prepare('SELECT results_status, title FROM tournaments WHERE id=? FOR UPDATE');
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch();
            if (!$tournament) {
                throw new DomainException('Tournament not found.');
            }
            $stmt = $db->prepare('SELECT COUNT(*) FROM results WHERE tournament_id=?');
            $stmt->execute([$tournamentId]);
            if ((int) $stmt->fetchColumn() === 0) {
                throw new DomainException('Enter at least one match result before publishing.');
            }
            $db->prepare('UPDATE tournaments SET results_status=?, results_published_at=NOW(), status=? WHERE id=?')
                ->execute([$status, $status === 'final' ? 'completed' : 'live', $tournamentId]);
            audit_log($adminId, 'results.published', 'tournament', $tournamentId, ['results_status' => $tournament['results_status']], ['results_status' => $status]);
            $stmt = $db->prepare(
                'SELECT DISTINCT rm.user_id FROM registration_members rm
                 JOIN registrations r ON r.id=rm.registration_id
                 WHERE r.tournament_id=? AND r.status=\'confirmed\''
            );
            $stmt->execute([$tournamentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
                notify_user((int) $memberId, 'results', $status === 'final' ? 'Final results posted' : 'Provisional results posted', $tournament['title'] . ' results are now ' . $status . '.', 'index.php?page=leaderboard&id=' . $tournamentId);
            }
        });
    }

    public static function leaderboard(int $tournamentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.id AS registration_id, r.created_at AS registration_time, r.captain_id,
                    p.display_name, t.name AS team_name,
                    COUNT(res.id) AS matches_played,
                    COALESCE(SUM(res.kills),0) AS total_kills,
                    COALESCE(SUM(res.placement_points),0) AS placement_points,
                    COALESCE(SUM(res.kill_points),0) AS kill_points,
                    COALESCE(SUM(res.bonus_points),0) AS bonus_points,
                    COALESCE(SUM(res.penalty_points),0) AS penalty_points,
                    COALESCE(SUM(res.total_points),0) AS total_points,
                    MIN(NULLIF(res.placement_rank,0)) AS best_rank
             FROM registrations r
             JOIN registration_members rm ON rm.registration_id=r.id AND rm.user_id=r.captain_id
             LEFT JOIN profiles p ON p.user_id=r.captain_id
             LEFT JOIN teams t ON t.id=r.team_id
             LEFT JOIN results res ON res.registration_id=r.id AND res.tournament_id=r.tournament_id
             WHERE r.tournament_id=? AND r.status=\'confirmed\'
             GROUP BY r.id, r.created_at, r.captain_id, p.display_name, t.name'
        );
        $stmt->execute([$tournamentId]);
        $rows = $stmt->fetchAll();
        $stmt = Database::connection()->prepare('SELECT tie_breakers FROM tournaments WHERE id=?');
        $stmt->execute([$tournamentId]);
        $encoded = $stmt->fetchColumn();
        $tieBreakers = self::decodeTieBreakers(is_string($encoded) ? $encoded : null);
        usort($rows, static function (array $a, array $b) use ($tieBreakers): int {
            foreach ($tieBreakers as $key) {
                if ($key === 'registration_time') {
                    $cmp = strcmp((string) $a[$key], (string) $b[$key]);
                } else {
                    $av = $key === 'best_rank' ? (int) ($a[$key] ?? PHP_INT_MAX) : (int) $a[$key];
                    $bv = $key === 'best_rank' ? (int) ($b[$key] ?? PHP_INT_MAX) : (int) $b[$key];
                    $cmp = $key === 'best_rank' ? $av <=> $bv : $bv <=> $av;
                }
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return (int) $a['registration_id'] <=> (int) $b['registration_id'];
        });
        foreach ($rows as $index => &$row) {
            $row['position'] = $index + 1;
            $row['display_name'] = $row['team_name'] ?: ($row['display_name'] ?: 'Player');
            foreach (['matches_played','total_kills','placement_points','kill_points','bonus_points','penalty_points','total_points'] as $number) {
                $row[$number] = (int) $row[$number];
            }
        }
        unset($row);
        return $rows;
    }

    public static function decodeTieBreakers(?string $json): array
    {
        $decoded = $json ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return ['total_points', 'total_kills', 'placement_points', 'best_rank', 'registration_time'];
        }
        try {
            return self::validateTieBreakers($decoded);
        } catch (InvalidArgumentException) {
            return ['total_points', 'total_kills', 'placement_points', 'best_rank', 'registration_time'];
        }
    }

    private static function validateTieBreakers(array $tieBreakers): array
    {
        if (!$tieBreakers) {
            throw new InvalidArgumentException('Select at least one tie-breaker.');
        }
        $clean = [];
        foreach ($tieBreakers as $key) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_TIE_BREAKERS, true)) {
                throw new InvalidArgumentException('One or more tie-breakers are invalid.');
            }
            if (!in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        if (!in_array('total_points', $clean, true)) {
            array_unshift($clean, 'total_points');
        }
        return $clean;
    }
}

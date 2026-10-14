<?php
declare(strict_types=1);

final class TeamService
{
    public static function create(int $captainId, string $name): int
    {
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Team name must be between 3 and 60 characters.');
        }
        if (preg_match('/[\x00-\x1F<>]/u', $name)) {
            throw new InvalidArgumentException('That team name contains unsupported characters.');
        }
        $db = Database::connection();
        return WalletService::atomic($db, function (PDO $db) use ($captainId, $name): int {
            $stmt = $db->prepare('INSERT INTO teams (name, captain_id) VALUES (?,?)');
            $stmt->execute([$name, $captainId]);
            $teamId = (int) $db->lastInsertId();
            $stmt = $db->prepare('INSERT INTO team_members (team_id, user_id, member_role) VALUES (?, ?, \'captain\')');
            $stmt->execute([$teamId, $captainId]);
            audit_log($captainId, 'team.created', 'team', $teamId, null, ['name' => $name]);
            return $teamId;
        });
    }

    public static function invite(int $captainId, int $teamId, string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid email address for the invitation.');
        }
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use ($captainId, $teamId, $email): void {
            $stmt = $db->prepare('SELECT * FROM teams WHERE id=? FOR UPDATE');
            $stmt->execute([$teamId]);
            $team = $stmt->fetch();
            if (!$team || (int) $team['captain_id'] !== $captainId) {
                throw new DomainException('Only this team’s captain can invite members.');
            }
            $stmt = $db->prepare('SELECT COUNT(*) FROM team_members WHERE team_id=?');
            $stmt->execute([$teamId]);
            if ((int) $stmt->fetchColumn() >= 4) {
                throw new DomainException('The team is already at the four-player maximum.');
            }
            $stmt = $db->prepare("SELECT id FROM users WHERE email=? AND status='active' LIMIT 1");
            $stmt->execute([$email]);
            $inviteeId = (int) $stmt->fetchColumn();
            if ($inviteeId < 1 || $inviteeId === $captainId) {
                throw new DomainException('That account could not be invited.');
            }
            $stmt = $db->prepare('SELECT 1 FROM team_members WHERE team_id=? AND user_id=?');
            $stmt->execute([$teamId, $inviteeId]);
            if ($stmt->fetchColumn()) {
                throw new DomainException('That player is already a team member.');
            }
            $stmt = $db->prepare('SELECT id, status FROM team_invitations WHERE team_id=? AND invitee_id=? FOR UPDATE');
            $stmt->execute([$teamId, $inviteeId]);
            $existing = $stmt->fetch();
            $expiry = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
            if ($existing && $existing['status'] === 'pending' && strtotime((string) $existing['expires_at'] . ' UTC') > time()) {
                throw new DomainException('An invitation is already pending for this account.');
            }
            if ($existing) {
                $stmt = $db->prepare(
                    "UPDATE team_invitations SET inviter_id=?, status='pending', invited_at=NOW(),
                     responded_at=NULL, expires_at=? WHERE id=?"
                );
                $stmt->execute([$captainId, $expiry, $existing['id']]);
                $invitationId = (int) $existing['id'];
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO team_invitations (team_id, inviter_id, invitee_id, expires_at) VALUES (?,?,?,?)'
                );
                $stmt->execute([$teamId, $captainId, $inviteeId, $expiry]);
                $invitationId = (int) $db->lastInsertId();
            }
            notify_user($inviteeId, 'invitation', 'Team invitation', 'You were invited to join ' . $team['name'] . '.', 'index.php?page=team');
            audit_log($captainId, 'team.invitation_sent', 'team_invitation', $invitationId, null, ['team_id' => $teamId, 'invitee_id' => $inviteeId]);
        });
    }

    public static function respond(int $userId, int $invitationId, string $choice): void
    {
        if (!in_array($choice, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose accept or decline.');
        }
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use ($userId, $invitationId, $choice): void {
            $stmt = $db->prepare(
                'SELECT ti.*, t.name, t.captain_id FROM team_invitations ti
                 JOIN teams t ON t.id=ti.team_id WHERE ti.id=? AND ti.invitee_id=? FOR UPDATE'
            );
            $stmt->execute([$invitationId, $userId]);
            $invitation = $stmt->fetch();
            if (!$invitation || $invitation['status'] !== 'pending' || strtotime((string) $invitation['expires_at'] . ' UTC') <= time()) {
                throw new DomainException('This team invitation is no longer available.');
            }
            if ($choice === 'accepted') {
                $stmt = $db->prepare('SELECT COUNT(*) FROM team_members WHERE team_id=?');
                $stmt->execute([$invitation['team_id']]);
                if ((int) $stmt->fetchColumn() >= 4) {
                    throw new DomainException('This team is now full.');
                }
                $stmt = $db->prepare('SELECT 1 FROM team_members WHERE team_id=? AND user_id=?');
                $stmt->execute([$invitation['team_id'], $userId]);
                if (!$stmt->fetchColumn()) {
                    $db->prepare('INSERT INTO team_members (team_id, user_id, member_role) VALUES (?, ?, \'member\')')
                        ->execute([$invitation['team_id'], $userId]);
                }
            }
            $db->prepare('UPDATE team_invitations SET status=?, responded_at=NOW() WHERE id=?')
                ->execute([$choice, $invitationId]);
            notify_user((int) $invitation['captain_id'], 'invitation', 'Team invitation ' . $choice, 'A player ' . $choice . ' the invite to ' . $invitation['name'] . '.', 'index.php?page=team');
            audit_log($userId, 'team.invitation_' . $choice, 'team_invitation', $invitationId, ['status' => 'pending'], ['status' => $choice]);
        });
    }

    public static function removeMember(int $captainId, int $teamId, int $memberId): void
    {
        if ($captainId === $memberId) {
            throw new DomainException('A captain cannot remove themselves from their team.');
        }
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use ($captainId, $teamId, $memberId): void {
            $stmt = $db->prepare('SELECT captain_id, name FROM teams WHERE id=? FOR UPDATE');
            $stmt->execute([$teamId]);
            $team = $stmt->fetch();
            if (!$team || (int) $team['captain_id'] !== $captainId) {
                throw new DomainException('Only this team’s captain can manage its members.');
            }
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM registrations r JOIN tournaments t ON t.id=r.tournament_id
                 WHERE r.team_id=? AND r.status='confirmed' AND t.status IN ('open','locked','live')"
            );
            $stmt->execute([$teamId]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new DomainException('Team members cannot change while the team has an active tournament entry.');
            }
            $stmt = $db->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=? AND member_role=\'member\'');
            $stmt->execute([$teamId, $memberId]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('That player is not an active member of this team.');
            }
            audit_log($captainId, 'team.member_removed', 'team', $teamId, ['member_id' => $memberId], null);
        });
    }
}

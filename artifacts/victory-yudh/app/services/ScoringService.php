<?php
declare(strict_types=1);

final class ScoringService
{
    private const ALLOWED_TIE_BREAKERS = [
        'total_points', 'total_kills', 'placement_points', 'best_rank', 'registration_time',
    ];

    public static function saveRules(
        int $tournamentId,
        int $pointsPerKill,
        array $placementPoints,
        array $tieBreakers,
        int $adminId,
        int $placementMultiplier = 1,
        int $bonusMultiplier = 1,
        int $penaltyMultiplier = 1
    ): void
    {
        if ($pointsPerKill < 0 || $pointsPerKill > 100) {
            throw new InvalidArgumentException('Points per kill must be between 0 and 100.');
        }
        foreach ([$placementMultiplier, $bonusMultiplier, $penaltyMultiplier] as $multiplier) {
            if ($multiplier < 0 || $multiplier > 100) {
                throw new InvalidArgumentException('Scoring multipliers must be between 0 and 100.');
            }
        }
        $placementPoints = [0 => 0] + $placementPoints;
        foreach ($placementPoints as $rank => $points) {
            if (!is_numeric($rank) || (int) $rank < 0 || (int) $rank > 1000 || !is_int($points) || $points < 0 || $points > 10000) {
                throw new InvalidArgumentException('Placement ranks and points are outside the allowed range.');
            }
        }
        $tieBreakers = self::validateTieBreakers($tieBreakers);
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use (
            $tournamentId, $pointsPerKill, $placementPoints, $tieBreakers, $adminId,
            $placementMultiplier, $bonusMultiplier, $penaltyMultiplier
        ): void {
            $stmt = $db->prepare('SELECT id, payouts_locked_at FROM tournaments WHERE id=? FOR UPDATE');
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch();
            if (!$tournament) {
                throw new DomainException('Tournament not found.');
            }
            if ($tournament['payouts_locked_at'] !== null) {
                throw new DomainException('Scoring rules are locked after automatic prize distribution.');
            }
            $stmt = $db->prepare(
                'SELECT placement_rank, placement_points, points_per_kill, placement_multiplier,
                        bonus_multiplier, penalty_multiplier, active
                 FROM scoring_rules WHERE tournament_id=?'
            );
            $stmt->execute([$tournamentId]);
            $before = $stmt->fetchAll();
            $encodedTieBreakers = json_encode($tieBreakers, JSON_THROW_ON_ERROR);
            $db->prepare('UPDATE scoring_rules SET active=0 WHERE tournament_id=?')->execute([$tournamentId]);
            $upsert = $db->prepare(
                'INSERT INTO scoring_rules
                   (tournament_id, placement_rank, placement_points, points_per_kill, placement_multiplier,
                    bonus_multiplier, penalty_multiplier, active, tie_breakers)
                 VALUES (?,?,?,?,?,?,?,1,?)
                 ON DUPLICATE KEY UPDATE placement_points=VALUES(placement_points),
                   points_per_kill=VALUES(points_per_kill), placement_multiplier=VALUES(placement_multiplier),
                   bonus_multiplier=VALUES(bonus_multiplier), penalty_multiplier=VALUES(penalty_multiplier),
                   active=1, tie_breakers=VALUES(tie_breakers)'
            );
            foreach ($placementPoints as $rank => $points) {
                $upsert->execute([
                    $tournamentId, (int) $rank, $points, $pointsPerKill, $placementMultiplier,
                    $bonusMultiplier, $penaltyMultiplier, $encodedTieBreakers,
                ]);
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
                    'placement_multiplier' => $placementMultiplier, 'bonus_multiplier' => $bonusMultiplier,
                    'penalty_multiplier' => $penaltyMultiplier,
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
        self::saveResults($tournamentId, $matchNumber, [[
            'registration_id' => $registrationId,
            'placement_rank' => $placementRank,
            'kills' => $kills,
            'bonus_points' => $bonusPoints,
            'penalty_points' => $penaltyPoints,
        ]], $adminId, false);
    }

    public static function saveResultsBulk(int $tournamentId, int $matchNumber, array $rows, int $adminId): int
    {
        return self::saveResults($tournamentId, $matchNumber, $rows, $adminId, true);
    }

    private static function saveResults(int $tournamentId, int $matchNumber, array $rows, int $adminId, bool $requireAll): int
    {
        if ($matchNumber < 1 || $matchNumber > 100 || !$rows || count($rows) > 1000) {
            throw new InvalidArgumentException('Choose a valid match and submit between 1 and 1,000 result rows.');
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Bulk result data is invalid.');
            }
            $values = [];
            foreach (['registration_id', 'placement_rank', 'kills', 'bonus_points', 'penalty_points'] as $key) {
                $value = $row[$key] ?? null;
                if (!is_int($value)) {
                    throw new InvalidArgumentException('Bulk result values must be whole numbers.');
                }
                $values[$key] = $value;
            }
            if ($values['registration_id'] < 1 || $values['placement_rank'] < 1 || $values['placement_rank'] > 1000 ||
                $values['kills'] < 0 || $values['kills'] > 1000 || $values['bonus_points'] < 0 ||
                $values['bonus_points'] > 10000 || $values['penalty_points'] < 0 || $values['penalty_points'] > 10000) {
                throw new InvalidArgumentException('Match result values are outside the allowed range.');
            }
            if (isset($normalized[$values['registration_id']])) {
                throw new InvalidArgumentException('A registration can appear only once in a match result submission.');
            }
            $normalized[$values['registration_id']] = $values;
        }

        $db = Database::connection();
        return WalletService::atomic($db, function (PDO $db) use (
            $tournamentId, $matchNumber, $normalized, $adminId, $requireAll
        ): int {
            $stmt = $db->prepare('SELECT id, results_status, payouts_locked_at FROM tournaments WHERE id=? FOR UPDATE');
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch();
            if (!$tournament) {
                throw new DomainException('Tournament not found.');
            }
            if ($tournament['payouts_locked_at'] !== null) {
                throw new DomainException('Results are locked after automatic prize distribution.');
            }
            $stmt = $db->prepare('SELECT id FROM registrations WHERE tournament_id=? AND status=\'confirmed\' ORDER BY id');
            $stmt->execute([$tournamentId]);
            $registeredIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            foreach (array_keys($normalized) as $registrationId) {
                if (!in_array((int) $registrationId, $registeredIds, true)) {
                    throw new DomainException('Choose active registrations from this tournament only.');
                }
            }
            if ($requireAll && (
                count($normalized) !== count($registeredIds) ||
                array_diff($registeredIds, array_map('intval', array_keys($normalized)))
            )) {
                throw new DomainException('Submit one result row for every confirmed player or team in the match.');
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
                'SELECT placement_rank, placement_points, points_per_kill, placement_multiplier,
                        bonus_multiplier, penalty_multiplier
                 FROM scoring_rules WHERE tournament_id=? AND active=1'
            );
            $stmt->execute([$tournamentId]);
            $rules = [];
            foreach ($stmt->fetchAll() as $rule) {
                $rules[(int) $rule['placement_rank']] = $rule;
            }
            $fallback = $rules[0] ?? [
                'placement_points' => 0, 'points_per_kill' => 0,
                'placement_multiplier' => 1, 'bonus_multiplier' => 1, 'penalty_multiplier' => 1,
            ];
            $findBefore = $db->prepare('SELECT * FROM results WHERE match_id=? AND registration_id=? FOR UPDATE');
            $save = $db->prepare(
                'INSERT INTO results
                   (tournament_id, match_id, registration_id, placement_rank, kills, bonus_input, penalty_input,
                    bonus_points, penalty_points, placement_points, kill_points, total_points, entered_by, updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE placement_rank=VALUES(placement_rank), kills=VALUES(kills),
                   bonus_input=VALUES(bonus_input), penalty_input=VALUES(penalty_input),
                   bonus_points=VALUES(bonus_points), penalty_points=VALUES(penalty_points),
                   placement_points=VALUES(placement_points), kill_points=VALUES(kill_points),
                   total_points=VALUES(total_points), updated_by=VALUES(updated_by)'
            );
            $resultIdQuery = $db->prepare('SELECT id FROM results WHERE match_id=? AND registration_id=?');
            $memberQuery = $db->prepare('SELECT user_id FROM registration_members WHERE registration_id=?');
            foreach ($normalized as $registrationId => $row) {
                $rule = $rules[$row['placement_rank']] ?? $fallback;
                $placementScore = (int) $rule['placement_points'] * (int) $rule['placement_multiplier'];
                $killScore = $row['kills'] * (int) $rule['points_per_kill'];
                $bonusScore = $row['bonus_points'] * (int) $rule['bonus_multiplier'];
                $penaltyScore = $row['penalty_points'] * (int) $rule['penalty_multiplier'];
                $total = $placementScore + $killScore + $bonusScore - $penaltyScore;

                $findBefore->execute([$matchId, $registrationId]);
                $before = $findBefore->fetch() ?: null;
                $save->execute([
                    $tournamentId, $matchId, $registrationId, $row['placement_rank'], $row['kills'],
                    $row['bonus_points'], $row['penalty_points'], $bonusScore, $penaltyScore,
                    $placementScore, $killScore, $total, $adminId, $adminId,
                ]);
                $resultIdQuery->execute([$matchId, $registrationId]);
                $resultId = (int) $resultIdQuery->fetchColumn();
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
                        'placement_rank' => $row['placement_rank'],
                        'kills' => $row['kills'],
                        'bonus_input' => $row['bonus_points'],
                        'penalty_input' => $row['penalty_points'],
                        'placement_points' => $placementScore,
                        'kill_points' => $killScore,
                        'bonus_points' => $bonusScore,
                        'penalty_points' => $penaltyScore,
                        'total_points' => $total,
                    ]
                );
                $memberQuery->execute([$registrationId]);
                foreach ($memberQuery->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
                    notify_user((int) $memberId, 'results', 'Match result updated', 'A result for Match ' . $matchNumber . ' was entered or corrected.', 'index.php?page=leaderboard&id=' . $tournamentId);
                }
            }
            if ($tournament['results_status'] === 'final') {
                $db->prepare("UPDATE tournaments SET results_status='provisional', status='live', results_published_at=NULL WHERE id=?")
                    ->execute([$tournamentId]);
            }
            return count($normalized);
        });
    }

    public static function recalculate(int $tournamentId, int $adminId): int
    {
        $db = Database::connection();
        return WalletService::atomic($db, fn(PDO $db) => self::recalculateWithinTransaction($db, $tournamentId, $adminId));
    }

    private static function recalculateWithinTransaction(PDO $db, int $tournamentId, int $adminId): int
    {
        $stmt = $db->prepare('SELECT id, payouts_locked_at FROM tournaments WHERE id=? FOR UPDATE');
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch();
        if (!$tournament) {
            throw new DomainException('Tournament not found.');
        }
        if ($tournament['payouts_locked_at'] !== null) {
            throw new DomainException('Results cannot be recalculated after automatic prize distribution.');
        }
        $stmt = $db->prepare(
            'SELECT r.id, r.placement_rank, r.kills, r.bonus_input, r.penalty_input,
                    sr.placement_points, sr.points_per_kill, sr.placement_multiplier,
                    sr.bonus_multiplier, sr.penalty_multiplier
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
            'UPDATE results SET placement_points=?, kill_points=?, bonus_points=?, penalty_points=?,
                    total_points=?, updated_by=? WHERE id=?'
        );
        foreach ($rows as $row) {
            $placement = (int) ($row['placement_points'] ?? 0) * (int) ($row['placement_multiplier'] ?? 1);
            $killPoints = (int) $row['kills'] * (int) ($row['points_per_kill'] ?? 0);
            $bonus = (int) $row['bonus_input'] * (int) ($row['bonus_multiplier'] ?? 1);
            $penalty = (int) $row['penalty_input'] * (int) ($row['penalty_multiplier'] ?? 1);
            $total = $placement + $killPoints + $bonus - $penalty;
            $update->execute([$placement, $killPoints, $bonus, $penalty, $total, $adminId, $row['id']]);
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
            $stmt = $db->prepare(
                'SELECT id, results_status, title, prize_pool_minor, auto_prizes_enabled,
                        rank_prize_percentages, kill_reward_per_kill_minor, payouts_locked_at
                 FROM tournaments WHERE id=? FOR UPDATE'
            );
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch();
            if (!$tournament) {
                throw new DomainException('Tournament not found.');
            }
            if ($tournament['payouts_locked_at'] !== null) {
                if ($status === 'final' && $tournament['results_status'] === 'final') {
                    return;
                }
                throw new DomainException('Published results cannot be changed after automatic prize distribution.');
            }
            $stmt = $db->prepare('SELECT COUNT(*) FROM results WHERE tournament_id=?');
            $stmt->execute([$tournamentId]);
            if ((int) $stmt->fetchColumn() === 0) {
                throw new DomainException('Enter at least one match result before publishing.');
            }
            if ($status === 'final' && (int) $tournament['auto_prizes_enabled'] === 1) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM registrations WHERE tournament_id=? AND status=\'confirmed\'');
                $stmt->execute([$tournamentId]);
                $confirmedCount = (int) $stmt->fetchColumn();
                $stmt = $db->prepare('SELECT COUNT(DISTINCT registration_id) FROM results WHERE tournament_id=?');
                $stmt->execute([$tournamentId]);
                if ((int) $stmt->fetchColumn() < $confirmedCount) {
                    throw new DomainException('Enter results for every confirmed player or team before finalizing automatic payouts.');
                }
                self::distributeAutomaticPrizesWithinTransaction($db, $tournament, $adminId);
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

    private static function distributeAutomaticPrizesWithinTransaction(PDO $db, array $tournament, int $adminId): void
    {
        $tournamentId = (int) $tournament['id'];
        $poolMinor = (int) $tournament['prize_pool_minor'];
        $perKillMinor = (int) $tournament['kill_reward_per_kill_minor'];
        if ($poolMinor < 1) {
            throw new DomainException('Automatic prize distribution requires a prize pool greater than zero.');
        }
        $rankPercentages = self::decodeRankPrizePercentages(
            is_string($tournament['rank_prize_percentages']) ? $tournament['rank_prize_percentages'] : null
        );
        if (!$rankPercentages && $perKillMinor < 1) {
            throw new DomainException('Configure rank percentages or a per-kill reward before finalizing payouts.');
        }

        $leaderboard = self::leaderboard($tournamentId);
        if (!$leaderboard) {
            throw new DomainException('Automatic prizes require at least one confirmed player or team.');
        }
        $remainingPool = $poolMinor;
        $payouts = [];
        foreach ($leaderboard as $row) {
            $killAward = (int) $row['total_kills'] * $perKillMinor;
            $payouts[(int) $row['registration_id']] = $killAward;
            $remainingPool -= $killAward;
            if ($remainingPool < 0) {
                throw new DomainException('Per-kill rewards exceed the tournament prize pool.');
            }
        }

        $groups = [];
        foreach ($leaderboard as $row) {
            $groups[(int) $row['position']][] = $row;
        }
        ksort($groups, SORT_NUMERIC);
        $rankPool = $remainingPool;
        $cumulativePercent = 0;
        foreach ($groups as $position => $group) {
            $groupPercent = 0;
            for ($offset = 0; $offset < count($group); $offset++) {
                $groupPercent += $rankPercentages[$position + $offset] ?? 0;
            }
            $startAmount = intdiv($rankPool * $cumulativePercent, 100);
            $cumulativePercent += $groupPercent;
            $endAmount = intdiv($rankPool * $cumulativePercent, 100);
            $groupAmount = $endAmount - $startAmount;
            usort($group, static fn(array $a, array $b): int => (int) $a['registration_id'] <=> (int) $b['registration_id']);
            $eachAmount = intdiv($groupAmount, count($group));
            $extraPaise = $groupAmount % count($group);
            foreach ($group as $index => $row) {
                $registrationId = (int) $row['registration_id'];
                $payouts[$registrationId] += $eachAmount + ($index < $extraPaise ? 1 : 0);
            }
        }

        $totalPaid = 0;
        $paidEntries = 0;
        $registrationStmt = $db->prepare(
            'SELECT captain_id FROM registrations WHERE id=? AND tournament_id=? AND status=\'confirmed\''
        );
        $memberStmt = $db->prepare('SELECT user_id FROM registration_members WHERE registration_id=?');
        foreach ($leaderboard as $row) {
            $registrationId = (int) $row['registration_id'];
            $amount = $payouts[$registrationId] ?? 0;
            if ($amount < 1) {
                continue;
            }
            $registrationStmt->execute([$registrationId, $tournamentId]);
            $captainId = (int) $registrationStmt->fetchColumn();
            if ($captainId < 1) {
                throw new DomainException('Automatic prize recipient is no longer active.');
            }
            WalletService::post(
                $db,
                $captainId,
                'prize',
                $amount,
                $amount,
                0,
                'auto-prize:' . $tournamentId . ':' . $registrationId,
                'tournament',
                $tournamentId,
                'Automatic tournament prize · position #' . $row['position']
            );
            $memberStmt->execute([$registrationId]);
            foreach ($memberStmt->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
                notify_user(
                    (int) $memberId,
                    'prize',
                    'Tournament prize credited',
                    format_money($amount) . ' was credited to the team captain’s wallet.',
                    'index.php?page=wallet'
                );
            }
            $totalPaid += $amount;
            $paidEntries++;
        }
        if ($totalPaid > $poolMinor) {
            throw new LogicException('Calculated tournament payouts exceed the configured prize pool.');
        }
        if ($totalPaid > 0) {
            $db->prepare('UPDATE tournaments SET payouts_locked_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$tournamentId]);
        }
        audit_log($adminId, 'tournament.prizes_auto_credited', 'tournament', $tournamentId, null, [
            'paid_entries' => $paidEntries,
            'amount_minor' => $totalPaid,
            'prize_pool_minor' => $poolMinor,
            'per_kill_minor' => $perKillMinor,
            'rank_percentages' => $rankPercentages,
            'payouts_locked' => $totalPaid > 0,
        ]);
    }

    private static function decodeRankPrizePercentages(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new DomainException('The configured rank prize schedule is invalid.');
        }
        $percentages = [];
        $total = 0;
        foreach ($decoded as $rank => $percentage) {
            if (!ctype_digit((string) $rank) || (int) $rank < 1 || (int) $rank > 1000 ||
                !is_numeric($percentage) || (int) $percentage < 1 || (int) $percentage > 100) {
                throw new DomainException('The configured rank prize schedule is invalid.');
            }
            $percentages[(int) $rank] = (int) $percentage;
            $total += (int) $percentage;
        }
        if ($total !== 100) {
            throw new DomainException('Rank prize percentages must add up to exactly 100%.');
        }
        return $percentages;
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
        $rankTieBreakers = array_values(array_filter($tieBreakers, static fn(string $key): bool => $key !== 'registration_time'));
        $position = 0;
        $previous = null;
        foreach ($rows as $index => &$row) {
            $sameRank = $previous !== null;
            if ($sameRank) {
                foreach ($rankTieBreakers as $key) {
                    $left = $key === 'best_rank' ? (int) ($previous[$key] ?? PHP_INT_MAX) : (int) $previous[$key];
                    $right = $key === 'best_rank' ? (int) ($row[$key] ?? PHP_INT_MAX) : (int) $row[$key];
                    if ($left !== $right) {
                        $sameRank = false;
                        break;
                    }
                }
            }
            if (!$sameRank) {
                $position = $index + 1;
            }
            $row['position'] = $position;
            $row['display_name'] = $row['team_name'] ?: ($row['display_name'] ?: 'Player');
            foreach (['matches_played','total_kills','placement_points','kill_points','bonus_points','penalty_points','total_points'] as $number) {
                $row[$number] = (int) $row[$number];
            }
            $previous = $row;
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

<?php
declare(strict_types=1);

function fallback_for_action(string $action): string
{
    $tournamentId = filter_var($_POST['tournament_id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT);
    return match ($action) {
        'register' => 'index.php?page=register',
        'login' => 'index.php?page=login',
        'profile-save', 'password-change' => 'index.php?page=profile',
        'team-create', 'team-invite', 'invitation-response', 'team-remove' => 'index.php?page=team',
        'withdraw-request', 'deposit-request' => 'index.php?page=wallet',
        'support-submit' => 'index.php?page=support',
        'admin-tournament-save' => 'index.php?page=admin-tournaments',
        'admin-room-save' => 'index.php?page=admin-room&id=' . max(0, (int) $tournamentId),
        'admin-result-save', 'admin-results-bulk-save', 'admin-scoring-save', 'admin-results-import',
        'admin-results-recalculate', 'admin-results-publish', 'admin-prize-credit' => 'index.php?page=admin-results&id=' . max(0, (int) $tournamentId),
        'admin-cancel-tournament', 'admin-refund-registration' => 'index.php?page=admin-tournaments',
        'admin-withdraw-review' => 'index.php?page=admin-withdrawals',
        'admin-user-toggle' => 'index.php?page=admin-users',
        'admin-team-remove-member' => 'index.php?page=admin-teams',
        'admin-deposit-review', 'admin-wallet-adjust' => 'index.php?page=admin-wallet',
        'admin-notification-send' => 'index.php?page=admin-notifications',
        'admin-announcement-save', 'admin-announcement-toggle' => 'index.php?page=admin-announcements',
        'admin-support-status' => 'index.php?page=admin-support',
        'admin-settings-save' => 'index.php?page=admin-settings',
        default => 'index.php',
    };
}

function redirect_to_post_return(string $fallback): never
{
    $target = $_POST['return_to'] ?? '';
    if (is_string($target) && preg_match('/^index\.php\?page=[a-z0-9-]+(?:&id=[0-9]+)?$/', $target) === 1) {
        redirect($target);
    }
    redirect($fallback);
}

function local_datetime_to_utc(string $value): string
{
    if ($value === '') {
        throw new InvalidArgumentException('Complete the required date and time fields.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone((string) config('timezone', 'Asia/Kolkata')));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        throw new InvalidArgumentException('Enter a valid date and time.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function parse_placement_points(string $text): array
{
    $rules = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^([1-9][0-9]{0,2})\s*[:=]\s*(0|[1-9][0-9]{0,3})$/', $line, $match)) {
            throw new InvalidArgumentException('Placement points must use one rank=points pair per line.');
        }
        $rank = (int) $match[1];
        if (array_key_exists($rank, $rules)) {
            throw new InvalidArgumentException('A placement rank is listed more than once.');
        }
        $rules[$rank] = (int) $match[2];
    }
    if (!$rules) {
        throw new InvalidArgumentException('Enter at least one placement points rule.');
    }
    ksort($rules, SORT_NUMERIC);
    return $rules;
}

function parse_rank_prize_percentages(string $text): array
{
    $percentages = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^([1-9][0-9]{0,2})\s*[:=]\s*(100|[1-9][0-9]?)$/', $line, $match)) {
            throw new InvalidArgumentException('Prize shares must use one rank=percent pair per line.');
        }
        $rank = (int) $match[1];
        if (array_key_exists($rank, $percentages)) {
            throw new InvalidArgumentException('A prize rank is listed more than once.');
        }
        $percentages[$rank] = (int) $match[2];
    }
    if ($percentages && array_sum($percentages) !== 100) {
        throw new InvalidArgumentException('Configured prize percentages must add up to exactly 100%.');
    }
    ksort($percentages, SORT_NUMERIC);
    return $percentages;
}

function store_tournament_banner(?array $upload, ?string $currentPath): ?string
{
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }
    if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($upload['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new InvalidArgumentException('Banner upload must be smaller than 3 MB.');
    }
    $tmp = $upload['tmp_name'] ?? '';
    if (!is_string($tmp) || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Banner upload could not be verified.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException('Use a JPEG, PNG, or WebP tournament banner.');
    }
    $directory = dirname(__DIR__) . '/public/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Upload storage is not available.');
    }
    $filename = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmp, $directory . '/' . $filename)) {
        throw new RuntimeException('The banner could not be saved.');
    }
    return 'uploads/' . $filename;
}

function handle_post_action(): never
{
    if (!is_post()) {
        http_response_code(405);
        header('Allow: POST');
        render_error_page(405, 'This action requires a form submission.');
        exit;
    }
    verify_csrf();
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    $user = current_user();
    $db = Database::connection();

    switch ($action) {
        case 'register': {
            if ($user) {
                redirect('index.php?page=dashboard');
            }
            if (!rate_limit('register', 5, 3600)) {
                throw new DomainException('Too many account attempts. Try again later.');
            }
            $email = mb_strtolower(post_string('email', 254, true));
            $displayName = post_string('display_name', 80, true);
            $uid = post_string('free_fire_uid', 32);
            $password = post_string('password', 256, true);
            $confirm = post_string('password_confirm', 256, true);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($displayName) < 2) {
                throw new InvalidArgumentException('Enter a valid email and a player name of at least 2 characters.');
            }
            if ($uid !== '' && !preg_match('/^[0-9]{5,32}$/', $uid)) {
                throw new InvalidArgumentException('Free Fire UID must be 5 to 32 digits.');
            }
            if (strlen($password) < 12 || !hash_equals($password, $confirm)) {
                throw new InvalidArgumentException('Passwords must match and be at least 12 characters.');
            }
            $stmt = $db->prepare('SELECT id FROM users WHERE email=?');
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                throw new DomainException('An account with that email already exists.');
            }
            try {
                WalletService::atomic($db, function (PDO $db) use ($email, $displayName, $uid, $password): void {
                    $stmt = $db->prepare('INSERT INTO users (email, password_hash) VALUES (?,?)');
                    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
                    $userId = (int) $db->lastInsertId();
                    $db->prepare('INSERT INTO profiles (user_id, display_name, free_fire_uid) VALUES (?,?,?)')
                        ->execute([$userId, $displayName, $uid === '' ? null : $uid]);
                    $db->prepare('INSERT INTO wallet_accounts (user_id) VALUES (?)')->execute([$userId]);
                    audit_log($userId, 'account.created', 'user', $userId, null, ['role' => 'user']);
                });
            } catch (PDOException $e) {
                $isDuplicateKey = $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062;
                if (!$isDuplicateKey) {
                    throw $e;
                }
                $stmt = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetchColumn()) {
                    throw new DomainException('An account with that email already exists.');
                }
                if ($uid !== '') {
                    $stmt = $db->prepare('SELECT user_id FROM profiles WHERE free_fire_uid=? LIMIT 1');
                    $stmt->execute([$uid]);
                    if ($stmt->fetchColumn()) {
                        throw new DomainException('That Free Fire UID is already linked to another account.');
                    }
                }
                throw $e;
            }
            $stmt = $db->prepare('SELECT id FROM users WHERE email=?');
            $stmt->execute([$email]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $stmt->fetchColumn();
            unset($_SESSION['_csrf']);
            flash('success', 'Your Victory Yudh player account is ready.');
            redirect('index.php?page=dashboard');
        }
        case 'login': {
            if ($user) {
                redirect('index.php?page=dashboard');
            }
            if (!rate_limit('login', 8, 900)) {
                throw new DomainException('Too many sign-in attempts. Wait 15 minutes and try again.');
            }
            $email = mb_strtolower(post_string('email', 254, true));
            $password = post_string('password', 256, true);
            $stmt = $db->prepare('SELECT id, password_hash, status FROM users WHERE email=? LIMIT 1');
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if (!$row || $row['status'] !== 'active' || !password_verify($password, $row['password_hash'])) {
                throw new DomainException('Email or password is incorrect.');
            }
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            }
            $db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$row['id']]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $row['id'];
            unset($_SESSION['_csrf']);
            flash('success', 'Welcome back to the arena.');
            redirect('index.php?page=dashboard');
        }
        case 'logout': {
            if (!$user) {
                redirect('index.php');
            }
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], true);
            }
            session_destroy();
            session_start();
            flash('success', 'You have been signed out.');
            redirect('index.php');
        }
        case 'profile-save': {
            $user = require_auth();
            $name = post_string('display_name', 80, true);
            $uid = post_string('free_fire_uid', 32);
            if (mb_strlen($name) < 2 || ($uid !== '' && !preg_match('/^[0-9]{5,32}$/', $uid))) {
                throw new InvalidArgumentException('Check the player name and Free Fire UID.');
            }
            $db->prepare('UPDATE profiles SET display_name=?, free_fire_uid=? WHERE user_id=?')
                ->execute([$name, $uid === '' ? null : $uid, $user['id']]);
            flash('success', 'Profile details updated.');
            redirect('index.php?page=profile');
        }
        case 'password-change': {
            $user = require_auth();
            $current = post_string('current_password', 256, true);
            $new = post_string('new_password', 256, true);
            $confirm = post_string('new_password_confirm', 256, true);
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=?');
            $stmt->execute([$user['id']]);
            $hash = (string) $stmt->fetchColumn();
            if (!password_verify($current, $hash)) {
                throw new DomainException('Current password is incorrect.');
            }
            if (strlen($new) < 12 || !hash_equals($new, $confirm)) {
                throw new InvalidArgumentException('New passwords must match and be at least 12 characters.');
            }
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            flash('success', 'Password changed successfully.');
            redirect('index.php?page=profile');
        }
        case 'join-tournament': {
            $user = require_auth();
            $tournamentId = post_int('tournament_id', 1);
            $teamValue = $_POST['team_id'] ?? '';
            $teamId = $teamValue === '' ? null : filter_var($teamValue, FILTER_VALIDATE_INT);
            if ($teamValue !== '' && (!$teamId || $teamId < 1)) {
                throw new InvalidArgumentException('Choose a valid team.');
            }
            TournamentService::register((int) $user['id'], $tournamentId, $teamId ? (int) $teamId : null);
            flash('success', 'Tournament registration confirmed.');
            redirect_to_post_return('index.php?page=tournament&id=' . $tournamentId);
        }
        case 'team-create': {
            $user = require_auth();
            TeamService::create((int) $user['id'], post_string('team_name', 60, true));
            flash('success', 'Team created. Invite your players to complete the roster.');
            redirect('index.php?page=team');
        }
        case 'team-invite': {
            $user = require_auth();
            TeamService::invite((int) $user['id'], post_int('team_id', 1), post_string('email', 254, true));
            flash('success', 'Team invitation sent.');
            redirect('index.php?page=team');
        }
        case 'invitation-response': {
            $user = require_auth();
            TeamService::respond((int) $user['id'], post_int('invitation_id', 1), post_string('choice', 12, true));
            flash('success', 'Invitation response saved.');
            redirect('index.php?page=team');
        }
        case 'team-remove': {
            $user = require_auth();
            TeamService::removeMember((int) $user['id'], post_int('team_id', 1), post_int('member_id', 1));
            flash('success', 'Team member removed.');
            redirect('index.php?page=team');
        }
        case 'withdraw-request': {
            $user = require_auth();
            $amount = money_minor(post_string('amount_rupees', 20, true));
            $nonce = post_string('withdraw_nonce', 32, true);
            WithdrawalService::request((int) $user['id'], $amount, post_string('upi_id', 100, true), $nonce);
            flash('success', 'Withdrawal submitted. Funds are reserved pending review.');
            redirect('index.php?page=wallet');
        }
        case 'deposit-request': {
            $user = require_auth();
            $amount = money_minor(post_string('amount_rupees', 20, true));
            $nonce = post_string('deposit_nonce', 32, true);
            DepositService::request((int) $user['id'], $amount, post_string('utr_number', 120, true), $nonce);
            $_SESSION['_deposit_nonce'] = bin2hex(random_bytes(16));
            flash('success', 'Deposit request submitted. Wallet credit is pending admin verification.');
            redirect('index.php?page=wallet');
        }
        case 'notification-read': {
            $user = require_auth();
            $db->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')
                ->execute([post_int('notification_id', 1), $user['id']]);
            redirect('index.php?page=notifications');
        }
        case 'support-submit': {
            if (!rate_limit('support', 5, 3600)) {
                throw new DomainException('Too many support requests. Try again later.');
            }
            $email = mb_strtolower(post_string('email', 254, true));
            $subject = post_string('subject', 140, true);
            $message = post_string('message', 4000, true);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($message) < 10) {
                throw new InvalidArgumentException('Enter a valid email and a support message of at least 10 characters.');
            }
            $db->prepare('INSERT INTO support_requests (user_id, contact_email, subject, message) VALUES (?,?,?,?)')
                ->execute([$user['id'] ?? null, $email, $subject, $message]);
            flash('success', 'Your support request was submitted. Keep your transaction and tournament references handy.');
            redirect('index.php?page=support');
        }
        case 'admin-tournament-save': {
            $admin = require_admin();
            $id = isset($_POST['tournament_id']) && $_POST['tournament_id'] !== '' ? post_int('tournament_id', 1) : null;
            $title = post_string('title', 140, true);
            $description = post_string('description', 5000, true);
            $map = post_string('map_name', 60, true);
            $mode = post_string('mode', 10, true);
            $teamSize = TournamentService::sizeForMode($mode);
            $fee = money_minor(post_string('entry_fee', 20, true));
            $prize = money_minor(post_string('prize_pool', 20, true));
            $limit = post_int('participant_limit', $teamSize, 1000);
            $deadline = local_datetime_to_utc(post_string('registration_deadline', 20, true));
            $matchTime = local_datetime_to_utc(post_string('match_time', 20, true));
            $roomRelease = local_datetime_to_utc(post_string('room_release_at', 20, true));
            $rules = post_string('rules', 5000, true);
            $status = post_string('status', 12, true);
            if (!in_array($status, ['draft','open','locked','live'], true)) {
                throw new InvalidArgumentException('Choose a valid tournament status.');
            }
            if (strtotime($deadline . ' UTC') >= strtotime($matchTime . ' UTC')
                || strtotime($roomRelease . ' UTC') > strtotime($matchTime . ' UTC')) {
                throw new InvalidArgumentException('Registration must close before the match; room release cannot be after match time.');
            }
            if ($status === 'open' && strtotime($deadline . ' UTC') <= time()) {
                throw new InvalidArgumentException('An open tournament needs a future registration deadline.');
            }
            $placementRules = parse_placement_points(post_string('placement_points', 10000, true));
            $pointsPerKill = post_int('points_per_kill', 0, 100);
            $placementMultiplier = post_int('placement_multiplier', 0, 100);
            $bonusMultiplier = post_int('bonus_multiplier', 0, 100);
            $penaltyMultiplier = post_int('penalty_multiplier', 0, 100);
            $autoPrizesEnabled = post_string('auto_prizes_enabled', 1) === '1';
            $rankPrizePercentages = parse_rank_prize_percentages(post_string('rank_prize_percentages', 10000));
            $killRewardPerKillMinor = money_minor(post_string('kill_reward_per_kill', 20));
            if ($autoPrizesEnabled && $prize < 1) {
                throw new InvalidArgumentException('Automatic prize distribution requires a prize pool greater than zero.');
            }
            if ($autoPrizesEnabled && !$rankPrizePercentages && $killRewardPerKillMinor < 1) {
                throw new InvalidArgumentException('Configure rank shares or a per-kill reward before enabling automatic prizes.');
            }
            $rankPrizeJson = $rankPrizePercentages
                ? json_encode($rankPrizePercentages, JSON_THROW_ON_ERROR)
                : null;
            $tieBreakers = $_POST['tie_breakers'] ?? [];
            if (!is_array($tieBreakers)) {
                throw new InvalidArgumentException('Tie-breaker selection is invalid.');
            }
            $bannerPath = null;
            $before = null;
            WalletService::atomic($db, function (PDO $db) use (
                $admin, $id, $title, $description, $map, $mode, $teamSize, $fee, $prize,
                $limit, $deadline, $matchTime, $roomRelease, $rules, $status, $placementRules,
                $pointsPerKill, $placementMultiplier, $bonusMultiplier, $penaltyMultiplier,
                $autoPrizesEnabled, $rankPrizeJson, $killRewardPerKillMinor,
                $tieBreakers, &$bannerPath, &$before
            ): void {
                if ($id) {
                    $stmt = $db->prepare('SELECT * FROM tournaments WHERE id=? FOR UPDATE');
                    $stmt->execute([$id]);
                    $before = $stmt->fetch();
                    if (!$before || in_array($before['status'], ['cancelled','completed'], true)) {
                        throw new DomainException('This tournament cannot be edited.');
                    }
                    $stmt = $db->prepare(
                        "SELECT COUNT(*) FROM registrations WHERE tournament_id=? AND status='confirmed'"
                    );
                    $stmt->execute([$id]);
                    $hasEntries = (int) $stmt->fetchColumn() > 0;
                    if ($hasEntries && ($before['mode'] !== $mode || (int) $before['entry_fee_minor'] !== $fee)) {
                        throw new DomainException('Mode and entry fee cannot change after players have registered.');
                    }
                    $occupied = TournamentService::occupiedSlots($id);
                    if ($limit < $occupied) {
                        throw new DomainException('Participant limit cannot be lower than the current registered players.');
                    }
                    $bannerPath = store_tournament_banner($_FILES['banner'] ?? null, $before['banner_path']);
                    $stmt = $db->prepare(
                        'UPDATE tournaments SET title=?, description=?, map_name=?, mode=?, team_size=?,
                         entry_fee_minor=?, prize_pool_minor=?, participant_limit=?, registration_deadline=?,
                          match_time=?, room_release_at=?, rules=?, banner_path=?, auto_prizes_enabled=?,
                          rank_prize_percentages=?, kill_reward_per_kill_minor=?, status=? WHERE id=?'
                    );
                    $stmt->execute([
                        $title, $description, $map, $mode, $teamSize, $fee, $prize, $limit, $deadline,
                        $matchTime, $roomRelease, $rules, $bannerPath, (int) $autoPrizesEnabled,
                        $rankPrizeJson, $killRewardPerKillMinor, $status, $id,
                    ]);
                    $tournamentId = $id;
                } else {
                    $bannerPath = store_tournament_banner($_FILES['banner'] ?? null, null);
                    $slugBase = trim((string) preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($title)), '-');
                    $slug = mb_substr($slugBase, 0, 90) . '-' . bin2hex(random_bytes(4));
                    $stmt = $db->prepare(
                        'INSERT INTO tournaments
                         (slug, title, description, map_name, mode, team_size, entry_fee_minor, prize_pool_minor,
                          participant_limit, registration_deadline, match_time, room_release_at, rules, banner_path,
                          status, tie_breakers, auto_prizes_enabled, rank_prize_percentages,
                          kill_reward_per_kill_minor, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([
                        $slug, $title, $description, $map, $mode, $teamSize, $fee, $prize, $limit,
                        $deadline, $matchTime, $roomRelease, $rules, $bannerPath, $status,
                        json_encode($tieBreakers, JSON_THROW_ON_ERROR), (int) $autoPrizesEnabled,
                        $rankPrizeJson, $killRewardPerKillMinor, $admin['id'],
                    ]);
                    $tournamentId = (int) $db->lastInsertId();
                }
                ScoringService::saveRules(
                    $tournamentId, $pointsPerKill, $placementRules, $tieBreakers, (int) $admin['id'],
                    $placementMultiplier, $bonusMultiplier, $penaltyMultiplier
                );
                audit_log((int) $admin['id'], $id ? 'tournament.updated' : 'tournament.created', 'tournament', $tournamentId,
                    $before ? [
                        'title' => $before['title'], 'status' => $before['status'], 'mode' => $before['mode'],
                        'auto_prizes_enabled' => (int) $before['auto_prizes_enabled'],
                        'rank_prize_percentages' => $before['rank_prize_percentages'],
                        'kill_reward_per_kill_minor' => (int) $before['kill_reward_per_kill_minor'],
                    ] : null,
                    [
                        'title' => $title, 'status' => $status, 'mode' => $mode, 'team_size' => $teamSize,
                        'auto_prizes_enabled' => (int) $autoPrizesEnabled,
                        'rank_prize_percentages' => $rankPrizePercentages,
                        'kill_reward_per_kill_minor' => $killRewardPerKillMinor,
                    ]);
            });
            flash('success', 'Tournament and scoring rules saved.');
            redirect('index.php?page=admin-tournaments');
        }
        case 'admin-room-save': {
            $admin = require_admin();
            $id = post_int('tournament_id', 1);
            $roomId = post_string('room_id', 80);
            $roomPassword = post_string('room_password', 128);
            [$rid, $ridIv, $ridTag] = encrypt_private_value($roomId);
            [$rpass, $rpassIv, $rpassTag] = encrypt_private_value($roomPassword);
            $stmt = $db->prepare(
                'UPDATE tournaments SET room_id_enc=?, room_id_iv=?, room_id_tag=?,
                 room_password_enc=?, room_password_iv=?, room_password_tag=? WHERE id=?'
            );
            $stmt->execute([$rid, $ridIv, $ridTag, $rpass, $rpassIv, $rpassTag, $id]);
            if ($stmt->rowCount() === 0) {
                $check = $db->prepare('SELECT id FROM tournaments WHERE id=?');
                $check->execute([$id]);
                if (!$check->fetchColumn()) {
                    throw new DomainException('Tournament not found.');
                }
            }
            audit_log((int) $admin['id'], 'tournament.room_credentials_updated', 'tournament', $id,
                ['room_id' => '[REDACTED]', 'room_password' => '[REDACTED]'],
                ['room_id' => $roomId !== '', 'room_password' => $roomPassword !== '']);
            flash('success', 'Room details saved encrypted at rest.');
            redirect('index.php?page=admin-room&id=' . $id);
        }
        case 'admin-result-save': {
            $admin = require_admin();
            ScoringService::saveResult(
                post_int('tournament_id', 1),
                post_int('match_number', 1, 100),
                post_int('registration_id', 1),
                post_int('placement_rank', 1, 1000),
                post_int('kills', 0, 1000),
                post_int('bonus_points', 0, 10000),
                post_int('penalty_points', 0, 10000),
                (int) $admin['id'],
                post_string('match_note', 500)
            );
            flash('success', 'Result saved and leaderboard recalculated.');
            redirect('index.php?page=admin-results&id=' . post_int('tournament_id', 1));
        }
        case 'admin-results-bulk-save': {
            $admin = require_admin();
            $tournamentId = post_int('tournament_id', 1);
            $matchNumber = post_int('match_number', 1, 100);
            $encodedRows = post_string('results_json', 500000, true);
            try {
                $rows = json_decode($encodedRows, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('Bulk result data could not be read. Please review the table and try again.');
            }
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new InvalidArgumentException('Bulk result data must be a list of player or team rows.');
            }
            $count = ScoringService::saveResultsBulk(
                $tournamentId,
                $matchNumber,
                $rows,
                (int) $admin['id'],
                post_string('match_note', 500)
            );
            flash('success', $count . ' player/team results saved and the leaderboard recalculated.');
            redirect('index.php?page=admin-results&id=' . $tournamentId . '&match_number=' . $matchNumber);
        }
        case 'admin-scoring-save': {
            $admin = require_admin();
            $tournamentId = post_int('tournament_id', 1);
            $placementRules = parse_placement_points(post_string('placement_points', 10000, true));
            $pointsPerKill = post_int('points_per_kill', 0, 100);
            $placementMultiplier = post_int('placement_multiplier', 0, 100);
            $bonusMultiplier = post_int('bonus_multiplier', 0, 100);
            $penaltyMultiplier = post_int('penalty_multiplier', 0, 100);
            $tieBreakers = $_POST['tie_breakers'] ?? [];
            if (!is_array($tieBreakers)) {
                throw new InvalidArgumentException('Tie-breaker selection is invalid.');
            }
            ScoringService::saveRules(
                $tournamentId, $pointsPerKill, $placementRules, $tieBreakers, (int) $admin['id'],
                $placementMultiplier, $bonusMultiplier, $penaltyMultiplier
            );
            flash('success', 'Scoring formula saved and existing results recalculated.');
            redirect('index.php?page=admin-results&id=' . $tournamentId);
        }
        case 'admin-results-import': {
            $admin = require_admin();
            $tournamentId = post_int('tournament_id', 1);
            $file = $_FILES['results_csv'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 1024 * 1024
                || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                throw new InvalidArgumentException('Upload a CSV under 1 MB.');
            }
            $handle = fopen((string) $file['tmp_name'], 'rb');
            if (!$handle) {
                throw new InvalidArgumentException('CSV could not be read.');
            }
            $header = fgetcsv($handle);
            $baseHeader = ['match_number','registration_id','placement_rank','kills','bonus_points','penalty_points'];
            $header = is_array($header) ? array_map(static fn($value) => trim((string) $value), $header) : [];
            $hasMatchNote = $header === [...$baseHeader, 'match_note'];
            if ($header !== $baseHeader && !$hasMatchNote) {
                fclose($handle);
                throw new InvalidArgumentException('CSV header must contain the six result columns, optionally followed by match_note.');
            }
            $rows = [];
            $unique = [];
            $notesByMatch = [];
            while (($csv = fgetcsv($handle)) !== false) {
                if (count($rows) >= 300 || count($csv) !== ($hasMatchNote ? 7 : 6)) {
                    fclose($handle);
                    throw new InvalidArgumentException('CSV must contain no more than 300 complete rows.');
                }
                $numbers = [];
                foreach (array_slice($csv, 0, 6) as $value) {
                    if (!preg_match('/^(0|[1-9][0-9]{0,4})$/', trim((string) $value))) {
                        fclose($handle);
                        throw new InvalidArgumentException('Every CSV value must be a non-negative integer.');
                    }
                    $numbers[] = (int) trim((string) $value);
                }
                [$matchNo,$registrationId,$rank,$kills,$bonus,$penalty] = $numbers;
                if ($matchNo < 1 || $matchNo > 100 || $registrationId < 1 || $rank < 1 || $rank > 1000 || $kills > 1000 || $bonus > 10000 || $penalty > 10000) {
                    fclose($handle);
                    throw new InvalidArgumentException('CSV values are outside the allowed result ranges.');
                }
                $key = $matchNo . ':' . $registrationId;
                if (isset($unique[$key])) {
                    fclose($handle);
                    throw new InvalidArgumentException('CSV contains the same entry and match more than once.');
                }
                $unique[$key] = true;
                $matchNote = $hasMatchNote ? trim((string) ($csv[6] ?? '')) : null;
                if ($matchNote !== null && mb_strlen($matchNote) > 500) {
                    fclose($handle);
                    throw new InvalidArgumentException('Match notes in the CSV must be 500 characters or fewer.');
                }
                if (array_key_exists($matchNo, $notesByMatch) && $notesByMatch[$matchNo] !== $matchNote) {
                    fclose($handle);
                    throw new InvalidArgumentException('Every row for a match must use the same match_note.');
                }
                $notesByMatch[$matchNo] = $matchNote;
                $rows[] = [$matchNo,$registrationId,$rank,$kills,$bonus,$penalty,$matchNote];
            }
            fclose($handle);
            if (!$rows) {
                throw new InvalidArgumentException('CSV has no result rows.');
            }
            WalletService::atomic($db, function () use ($rows, $tournamentId, $admin): void {
                foreach ($rows as [$matchNo,$registrationId,$rank,$kills,$bonus,$penalty,$matchNote]) {
                    ScoringService::saveResult(
                        $tournamentId,
                        $matchNo,
                        $registrationId,
                        $rank,
                        $kills,
                        $bonus,
                        $penalty,
                        (int) $admin['id'],
                        $matchNote
                    );
                }
            });
            flash('success', count($rows) . ' result rows imported and validated.');
            redirect('index.php?page=admin-results&id=' . $tournamentId);
        }
        case 'admin-results-recalculate': {
            $admin = require_admin();
            $count = ScoringService::recalculate(post_int('tournament_id', 1), (int) $admin['id']);
            flash('success', $count . ' match result(s) recalculated.');
            redirect('index.php?page=admin-results&id=' . post_int('tournament_id', 1));
        }
        case 'admin-results-publish': {
            $admin = require_admin();
            $tournamentId = post_int('tournament_id', 1);
            ScoringService::publish($tournamentId, post_string('result_status', 12, true), (int) $admin['id']);
            flash('success', 'Results published.');
            redirect('index.php?page=admin-results&id=' . $tournamentId);
        }
        case 'admin-prize-credit': {
            $admin = require_admin();
            $tournamentId = post_int('tournament_id', 1);
            $registrationId = post_int('registration_id', 1);
            $recipientUserId = post_int('recipient_user_id', 1);
            $amount = money_minor(post_string('amount_rupees', 20, true));
            if ($amount < 1) {
                throw new InvalidArgumentException('Prize credit must be greater than zero.');
            }
            WalletService::atomic($db, function (PDO $db) use (
                $admin, $tournamentId, $registrationId, $recipientUserId, $amount
            ): void {
                $stmt = $db->prepare('SELECT results_status, auto_prizes_enabled FROM tournaments WHERE id=? FOR UPDATE');
                $stmt->execute([$tournamentId]);
                $tournament = $stmt->fetch();
                if (!$tournament || $tournament['results_status'] !== 'final') {
                    throw new DomainException('Finalize results before crediting a prize.');
                }
                if ((int) $tournament['auto_prizes_enabled'] === 1) {
                    throw new DomainException('This tournament uses automatic prize distribution; manual credits are disabled to prevent duplicate payouts.');
                }
                $leaderboard = ScoringService::leaderboard($tournamentId);
                $found = null;
                foreach ($leaderboard as $row) {
                    if ((int) $row['registration_id'] === $registrationId) {
                        $found = $row;
                        break;
                    }
                }
                if (!$found) {
                    throw new DomainException('Prize entry has no result in this tournament.');
                }
                $stmt = $db->prepare(
                    'SELECT u.email, p.display_name
                     FROM registration_members rm
                     JOIN registrations r ON r.id=rm.registration_id AND r.tournament_id=rm.tournament_id
                     JOIN users u ON u.id=rm.user_id
                     LEFT JOIN profiles p ON p.user_id=u.id
                     WHERE rm.registration_id=? AND rm.tournament_id=? AND rm.user_id=? AND r.status=\'confirmed\''
                );
                $stmt->execute([$registrationId, $tournamentId, $recipientUserId]);
                $recipient = $stmt->fetch();
                if (!$recipient) {
                    throw new DomainException('Prize recipient must be a joined player on this result entry.');
                }
                $stmt = $db->prepare(
                    'SELECT id FROM wallet_transactions WHERE idempotency_key=?'
                );
                $stmt->execute(['prize:' . $tournamentId . ':' . $registrationId]);
                if ($stmt->fetchColumn()) {
                    throw new DomainException('This registration already has a legacy captain prize credit. Review the ledger before issuing another prize.');
                }
                $recipientName = trim((string) ($recipient['display_name'] ?? ''));
                WalletService::post(
                    $db,
                    $recipientUserId,
                    'prize',
                    $amount,
                    $amount,
                    0,
                    'manual-prize:' . $tournamentId . ':' . $registrationId . ':' . $recipientUserId,
                    'tournament',
                    $tournamentId,
                    'Manual final tournament prize · position #' . $found['position']
                );
                notify_user(
                    $recipientUserId,
                    'prize',
                    'Tournament prize credited',
                    format_money($amount) . ' was credited to your wallet for position #' . $found['position'] . '.',
                    'index.php?page=wallet'
                );
                audit_log((int) $admin['id'], 'tournament.prize_credited', 'registration', $registrationId, null, [
                    'tournament_id' => $tournamentId,
                    'recipient_user_id' => $recipientUserId,
                    'recipient_email' => $recipient['email'],
                    'position' => $found['position'],
                    'amount_minor' => $amount,
                ]);
            });
            flash('success', 'Prize credit posted to the selected player’s wallet.');
            redirect('index.php?page=admin-results&id=' . $tournamentId);
        }
        case 'admin-cancel-tournament': {
            $admin = require_admin();
            $tournamentId = post_int('tournament_id', 1);
            WalletService::atomic($db, function (PDO $db) use ($admin, $tournamentId): void {
                $stmt = $db->prepare('SELECT status, title FROM tournaments WHERE id=? FOR UPDATE');
                $stmt->execute([$tournamentId]);
                $tournament = $stmt->fetch();
                if (!$tournament || in_array($tournament['status'], ['cancelled','completed'], true)) {
                    throw new DomainException('This tournament cannot be cancelled.');
                }
                $db->prepare("UPDATE tournaments SET status='cancelled' WHERE id=?")->execute([$tournamentId]);
                $stmt = $db->prepare("SELECT id FROM registrations WHERE tournament_id=? AND status='confirmed' FOR UPDATE");
                $stmt->execute([$tournamentId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $registrationId) {
                    TournamentService::refundRegistration((int) $registrationId, (int) $admin['id']);
                }
                audit_log((int) $admin['id'], 'tournament.cancelled', 'tournament', $tournamentId,
                    ['status' => $tournament['status']], ['status' => 'cancelled']);
            });
            flash('success', 'Tournament cancelled. Confirmed entry fees were refunded exactly once.');
            redirect('index.php?page=admin-tournaments');
        }
        case 'admin-refund-registration': {
            $admin = require_admin();
            TournamentService::refundRegistration(post_int('registration_id', 1), (int) $admin['id']);
            flash('success', 'Registration refund processed.');
            redirect('index.php?page=admin-tournaments');
        }
        case 'admin-withdraw-review': {
            $admin = require_admin();
            WithdrawalService::review(post_int('withdrawal_id', 1), (int) $admin['id'], post_string('decision', 12, true), post_string('note', 255));
            flash('success', 'Withdrawal status updated. Payout is only marked paid after an admin enters its confirmed reference.');
            redirect('index.php?page=admin-withdrawals');
        }
        case 'admin-deposit-review': {
            $admin = require_admin();
            DepositService::review(
                post_int('deposit_request_id', 1),
                (int) $admin['id'],
                post_string('decision', 12, true),
                post_string('note', 255)
            );
            flash('success', 'Deposit request reviewed. Only approved requests are credited.');
            redirect('index.php?page=admin-wallet');
        }
        case 'admin-wallet-adjust': {
            $admin = require_admin();
            $userId = post_int('user_id', 1);
            $direction = post_string('direction', 8, true);
            $amount = money_minor(post_string('amount_rupees', 20, true));
            $reason = post_string('reason', 255, true);
            $nonce = post_string('adjustment_nonce', 32, true);
            $posted = AdminWalletService::adjust(
                (int) $admin['id'],
                $userId,
                $direction,
                $amount,
                $reason,
                $nonce
            );
            $_SESSION['_wallet_adjustment_nonce'] = bin2hex(random_bytes(16));
            flash(
                'success',
                $posted
                    ? 'Wallet ' . ($direction === 'credit' ? 'credit' : 'debit') . ' posted to the ledger.'
                    : 'This wallet adjustment was already applied; no duplicate was posted.'
            );
            redirect('index.php?page=admin-wallet');
        }
        case 'admin-notification-send': {
            $admin = require_admin();
            $targetMode = post_string('target_mode', 12, true);
            $title = post_string('title', 120, true);
            $body = post_string('body', 500, true);
            $recipientIds = [];
            if ($targetMode === 'all') {
                $stmt = $db->query("SELECT id FROM users WHERE role='user' AND status='active' ORDER BY id");
                $recipientIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            } elseif ($targetMode === 'selected') {
                $rawIds = $_POST['user_ids'] ?? [];
                if (!is_array($rawIds) || count($rawIds) < 1 || count($rawIds) > 200) {
                    throw new InvalidArgumentException('Select between 1 and 200 active users.');
                }
                foreach ($rawIds as $rawId) {
                    $id = filter_var($rawId, FILTER_VALIDATE_INT);
                    if (!$id || $id < 1) {
                        throw new InvalidArgumentException('One or more selected users are invalid.');
                    }
                    $recipientIds[] = (int) $id;
                }
                $recipientIds = array_values(array_unique($recipientIds));
                $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
                $stmt = $db->prepare(
                    "SELECT id FROM users WHERE role='user' AND status='active' AND id IN ($placeholders)"
                );
                $stmt->execute($recipientIds);
                $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                if (count($validIds) !== count($recipientIds)) {
                    throw new DomainException('Selected users must all be active player accounts.');
                }
                $recipientIds = $validIds;
            } else {
                throw new InvalidArgumentException('Choose all users or selected users as the notification audience.');
            }
            if (!$recipientIds) {
                throw new DomainException('There are no active player accounts to notify.');
            }
            WalletService::atomic($db, function (PDO $db) use ($admin, $recipientIds, $title, $body, $targetMode): void {
                $stmt = $db->prepare(
                    "INSERT INTO notifications (user_id, notification_type, title, body, target_url)
                     VALUES (?, 'admin_message', ?, ?, 'index.php?page=notifications')"
                );
                foreach ($recipientIds as $recipientId) {
                    $stmt->execute([$recipientId, $title, $body]);
                }
                audit_log((int) $admin['id'], 'admin.notification_sent', 'notification', null, null, [
                    'target_mode' => $targetMode,
                    'recipient_count' => count($recipientIds),
                    'recipient_user_ids' => $targetMode === 'selected' ? $recipientIds : [],
                    'title' => $title,
                ]);
            });
            flash('success', 'Notification sent to ' . count($recipientIds) . ' user(s).');
            redirect('index.php?page=admin-notifications');
        }
        case 'admin-user-toggle': {
            $admin = require_admin();
            $id = post_int('user_id', 1);
            if ($id === (int) $admin['id']) {
                throw new DomainException('You cannot suspend your own admin account here.');
            }
            $stmt = $db->prepare('SELECT status, role FROM users WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target || $target['role'] === 'admin') {
                throw new DomainException('Admin account roles and protected accounts are not managed here.');
            }
            $newStatus = $target['status'] === 'active' ? 'suspended' : 'active';
            $db->prepare('UPDATE users SET status=? WHERE id=?')->execute([$newStatus, $id]);
            audit_log((int) $admin['id'], 'user.status_changed', 'user', $id, ['status' => $target['status']], ['status' => $newStatus]);
            flash('success', 'Player account status updated.');
            redirect('index.php?page=admin-users');
        }
        case 'admin-team-remove-member': {
            $admin = require_admin();
            TeamService::adminRemoveMember((int) $admin['id'], post_int('team_id', 1), post_int('member_id', 1));
            flash('success', 'Team member removed. Active tournament entries were protected.');
            redirect('index.php?page=admin-teams');
        }
        case 'admin-announcement-save': {
            $admin = require_admin();
            $title = post_string('title', 140, true);
            $body = post_string('body', 5000, true);
            $published = ($_POST['publish'] ?? '') === '1';
            WalletService::atomic($db, function (PDO $db) use ($admin, $title, $body, $published): void {
                $db->prepare(
                    "INSERT INTO announcements (title, body, status, published_at, created_by)
                     VALUES (?,?,?,IF(?=1,NOW(),NULL),?)"
                )->execute([$title, $body, $published ? 'published' : 'draft', $published ? 1 : 0, $admin['id']]);
                $id = (int) $db->lastInsertId();
                if ($published) {
                    $stmt = $db->query("SELECT id FROM users WHERE status='active'");
                    $notify = $db->prepare('INSERT INTO notifications (user_id, notification_type, title, body, target_url) VALUES (?,\'announcement\',?,?,?)');
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                        $notify->execute([(int) $userId, 'Victory Yudh update', mb_substr($title . ': ' . $body, 0, 500), 'index.php']);
                    }
                }
                audit_log((int) $admin['id'], 'announcement.created', 'announcement', $id, null, [
                    'title' => $title, 'status' => $published ? 'published' : 'draft',
                ]);
            });
            flash('success', 'Announcement saved.');
            redirect('index.php?page=admin-announcements');
        }
        case 'admin-announcement-toggle': {
            $admin = require_admin();
            $id = post_int('announcement_id', 1);
            WalletService::atomic($db, function (PDO $db) use ($admin, $id): void {
                $stmt = $db->prepare('SELECT * FROM announcements WHERE id=? FOR UPDATE');
                $stmt->execute([$id]);
                $item = $stmt->fetch();
                if (!$item) {
                    throw new DomainException('Announcement not found.');
                }
                $status = $item['status'] === 'published' ? 'archived' : 'published';
                $db->prepare('UPDATE announcements SET status=?, published_at=IF(?=\'published\',NOW(),published_at) WHERE id=?')
                    ->execute([$status, $status, $id]);
                if ($status === 'published' && !$item['published_at']) {
                    $stmt = $db->query("SELECT id FROM users WHERE status='active'");
                    $notify = $db->prepare('INSERT INTO notifications (user_id, notification_type, title, body, target_url) VALUES (?,\'announcement\',?,?,?)');
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                        $notify->execute([(int)$userId, 'Victory Yudh update', mb_substr($item['title'] . ': ' . $item['body'],0,500), 'index.php']);
                    }
                }
                audit_log((int) $admin['id'], 'announcement.' . $status, 'announcement', $id, ['status' => $item['status']], ['status' => $status]);
            });
            flash('success', 'Announcement status updated.');
            redirect('index.php?page=admin-announcements');
        }
        case 'admin-support-status': {
            $admin = require_admin();
            $id = post_int('request_id', 1);
            $status = post_string('status', 16, true);
            if (!in_array($status, ['open','in_progress','resolved','closed'], true)) {
                throw new InvalidArgumentException('Choose a valid support status.');
            }
            $stmt = $db->prepare('SELECT status FROM support_requests WHERE id=?');
            $stmt->execute([$id]);
            $before = $stmt->fetchColumn();
            if (!is_string($before)) {
                throw new DomainException('Support request not found.');
            }
            $db->prepare('UPDATE support_requests SET status=?, assigned_to=? WHERE id=?')
                ->execute([$status, $admin['id'], $id]);
            audit_log((int) $admin['id'], 'support.status_changed', 'support_request', $id, ['status' => $before], ['status' => $status]);
            flash('success', 'Support request updated.');
            redirect('index.php?page=admin-support');
        }
        case 'admin-settings-save': {
            $admin = require_admin();
            $minimum = money_minor(post_string('minimum_withdrawal', 20, true));
            $maximum = money_minor(post_string('maximum_withdrawal', 20, true));
            if ($minimum < 100 || $maximum < $minimum || $maximum > 50000000) {
                throw new InvalidArgumentException('Withdrawal limits must be at least ₹1 and the maximum cannot be lower than the minimum or greater than ₹500,000.');
            }
            WalletService::atomic($db, function (PDO $db) use ($admin, $minimum, $maximum): void {
                foreach ([
                    'min_withdrawal_minor' => $minimum,
                    'max_withdrawal_minor' => $maximum,
                ] as $key => $value) {
                    $stmt = $db->prepare(
                        'INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)'
                    );
                    $stmt->execute([$key, (string) $value, $admin['id']]);
                }
                audit_log((int) $admin['id'], 'settings.wallet_configuration_updated', 'settings', null, null, [
                    'min_withdrawal_minor' => $minimum, 'max_withdrawal_minor' => $maximum,
                ]);
            });
            flash('success', 'Wallet settings updated.');
            redirect('index.php?page=admin-settings');
        }
        default:
            throw new DomainException('That form action is not available.');
    }
}

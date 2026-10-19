<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$testsPassed = 0;
$testsFailed = 0;
$context = [];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expect_exception(string $class, callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return $error;
        }
        throw new RuntimeException('Expected ' . $class . ', received ' . get_class($error) . '.');
    }
    throw new RuntimeException('Expected ' . $class . ' but no exception was thrown.');
}

function run_case(string $name, callable $callback): void
{
    global $testsPassed, $testsFailed;
    try {
        $callback();
        $testsPassed++;
        echo "PASS - $name\n";
    } catch (Throwable $error) {
        $testsFailed++;
        echo 'FAIL - ' . $name . ': ' . get_class($error) . ' — ' . $error->getMessage() . "\n";
    }
}

function create_fixture_user(string $label, string $role = 'user'): int
{
    $db = Database::connection();
    $email = 'fixture-' . $label . '-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $password = 'Fixture-Password-123456';
    $stmt = $db->prepare('INSERT INTO users (email,password_hash,role) VALUES (?,?,?)');
    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $role]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO profiles (user_id,display_name) VALUES (?,?)')->execute([$id, 'Fixture ' . $label]);
    $db->prepare('INSERT INTO wallet_accounts (user_id) VALUES (?)')->execute([$id]);
    WalletService::atomic($db, fn(PDO $db) => WalletService::post(
        $db, $id, 'deposit', 100000, 100000, 0, 'test-fixture-credit:' . $id,
        'test_fixture', $id, 'Test-only isolated fixture balance'
    ));
    return $id;
}

function create_fixture_tournament(int $adminId, string $slug, string $mode, int $capacity, int $fee, ?int $deadlineOffset = null): int
{
    $db = Database::connection();
    $now = time();
    $deadline = gmdate('Y-m-d H:i:s', $now + ($deadlineOffset ?? 3600));
    $match = gmdate('Y-m-d H:i:s', $now + 86400);
    $stmt = $db->prepare(
        'INSERT INTO tournaments
         (slug,title,description,map_name,mode,team_size,entry_fee_minor,prize_pool_minor,participant_limit,
          registration_deadline,match_time,room_release_at,rules,status,tie_breakers,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'open\',?,?)'
    );
    $stmt->execute([
        $slug, '[TEST] ' . $slug, 'Integration-test tournament.', 'Bermuda',
        $mode, TournamentService::sizeForMode($mode), $fee, 0, $capacity,
        $deadline, $match, $match, 'Test rules.', json_encode(['total_points','total_kills','placement_points','best_rank','registration_time'], JSON_THROW_ON_ERROR),
        $adminId,
    ]);
    return (int) $db->lastInsertId();
}

$db = Database::connection();
$context['admin'] = create_fixture_user('admin', 'admin');
$adminEmailStmt = $db->prepare('SELECT email FROM users WHERE id=?');
$adminEmailStmt->execute([$context['admin']]);
$adminEmail = (string) $adminEmailStmt->fetchColumn();
$adminEmailFile = getenv('TEST_ADMIN_EMAIL_FILE');
if (is_string($adminEmailFile) && $adminEmailFile !== '') {
    file_put_contents($adminEmailFile, $adminEmail, LOCK_EX);
    chmod($adminEmailFile, 0600);
}
$context['solo'] = create_fixture_user('solo');
$context['overflow'] = create_fixture_user('overflow');
$context['duo_captain'] = create_fixture_user('duo-captain');
$context['duo_member'] = create_fixture_user('duo-member');
$context['squad_captain'] = create_fixture_user('squad-captain');
$context['squad_member_1'] = create_fixture_user('squad-member-1');
$context['squad_member_2'] = create_fixture_user('squad-member-2');
$context['squad_member_3'] = create_fixture_user('squad-member-3');
$context['score_a'] = create_fixture_user('score-a');
$context['score_b'] = create_fixture_user('score-b');
$context['refunder'] = create_fixture_user('refunder');
$context['wallet'] = create_fixture_user('wallet');
$context['deposit'] = create_fixture_user('deposit');
$context['adjustment'] = create_fixture_user('adjustment');
$context['withdrawal'] = create_fixture_user('withdrawal');

run_case('MariaDB migration creates core tables and records its version', function () use ($db): void {
    $tables = $db->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['schema_migrations','users','profiles','teams','team_members','team_invitations','tournaments',
        'scoring_rules','registrations','registration_members','matches','results','wallet_accounts',
        'wallet_transactions','withdrawal_requests',
        'notifications','announcements','settings','audit_logs','rate_limits','support_requests'] as $table) {
        check(in_array($table, $tables, true), 'Missing table ' . $table);
    }
    foreach (['payment_orders','payment_webhook_events','payment_refunds'] as $retiredTable) {
        check(!in_array($retiredTable, $tables, true), 'Retired billing table was created: ' . $retiredTable);
    }
    check((string) $db->query('SELECT version FROM schema_migrations WHERE version=\'001_initial\'')->fetchColumn() === '001_initial', 'Initial migration version missing.');
    check((string) $db->query('SELECT version FROM schema_migrations WHERE version=\'002_scoring_and_prize_rules\'')->fetchColumn() === '002_scoring_and_prize_rules', 'Scoring and prize migration version missing.');
    check((string) $db->query('SELECT version FROM schema_migrations WHERE version=\'004_user_auth_columns\'')->fetchColumn() === '004_user_auth_columns', 'User auth column migration version missing.');
    $userColumns = $db->query(
        'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=\'users\''
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (['id', 'email', 'password_hash', 'role', 'status', 'email_verified_at', 'last_login_at', 'created_at', 'updated_at'] as $column) {
        check(in_array($column, $userColumns, true), 'Missing required users column ' . $column);
    }
});

run_case('authentication uses password hashes and verifies correct/incorrect passwords', function () use ($db, $context): void {
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=?');
    $stmt->execute([$context['solo']]);
    $hash = (string) $stmt->fetchColumn();
    check($hash !== 'Fixture-Password-123456', 'Password was stored as plaintext.');
    check(password_verify('Fixture-Password-123456', $hash), 'Correct password did not verify.');
    check(!password_verify('incorrect password', $hash), 'Incorrect password verified.');
});

run_case('admin permission and CSRF checks reject non-admin and forged tokens', function () use ($context): void {
    check(has_admin_role(['role'=>'admin','status'=>'active']), 'Active admin was denied.');
    check(!has_admin_role(['role'=>'user','status'=>'active']), 'Normal user received admin access.');
    check(!has_admin_role(['role'=>'admin','status'=>'suspended']), 'Suspended admin received admin access.');
    $token = csrf_token();
    check(csrf_is_valid($token), 'Current CSRF token was rejected.');
    check(!csrf_is_valid('not-the-current-token'), 'Forged CSRF token was accepted.');
    check(!csrf_is_valid(['array']), 'Non-string CSRF input was accepted.');
});

run_case('mode validation enforces Solo, Duo, and Squad sizes', function (): void {
    check(TournamentService::sizeForMode('solo') === 1, 'Solo size mismatch.');
    check(TournamentService::sizeForMode('duo') === 2, 'Duo size mismatch.');
    check(TournamentService::sizeForMode('squad') === 4, 'Squad size mismatch.');
    expect_exception(InvalidArgumentException::class, fn() => TournamentService::sizeForMode('trio'));
});

run_case('wallet uses integer minor units and idempotent immutable ledger entries', function () use ($db, $context): void {
    $userId = $context['wallet'];
    $before = WalletService::balance($userId);
    WalletService::atomic($db, fn(PDO $db) => WalletService::post($db, $userId, 'deposit', 2345, 2345, 0, 'test-wallet-idem', 'test', 1));
    WalletService::atomic($db, fn(PDO $db) => WalletService::post($db, $userId, 'deposit', 2345, 2345, 0, 'test-wallet-idem', 'test', 1));
    $after = WalletService::balance($userId);
    check((int) $after['balance_minor'] === (int) $before['balance_minor'] + 2345, 'Retry credited the balance twice.');
    $stmt = $db->prepare('SELECT COUNT(*) FROM wallet_transactions WHERE idempotency_key=\'test-wallet-idem\'');
    $stmt->execute();
    check((int) $stmt->fetchColumn() === 1, 'Duplicate ledger row was created.');
    expect_exception(DomainException::class, fn() => WalletService::atomic($db, fn(PDO $db) => WalletService::post($db, $userId, 'deposit', 999, 999, 0, 'test-wallet-idem')));
    expect_exception(DomainException::class, fn() => WalletService::atomic($db, fn(PDO $db) => WalletService::post($db, $userId, 'entry_fee', 99999999, -99999999, 0, 'test-insufficient')));
    check(money_minor('25.09') === 2509 && format_money_input(2509) === '25.09', 'Integer money conversion lost paise precision.');
});

run_case('manual deposit verification requires a unique UTR and credits only after approval', function () use ($db, $context): void {
    $userId = $context['deposit'];
    check(DepositService::UPI_ID === '787744284@fam', 'The manual deposit UPI ID changed.');
    check(
        DepositService::QR_IMAGE_URL === 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=upi://pay?pa=787744284@fam%26pn=Niyant',
        'The manual deposit QR code URL changed.'
    );

    $before = (int) WalletService::balance($userId)['balance_minor'];
    $amount = 25750;
    $nonce = bin2hex(random_bytes(16));
    $requestId = DepositService::request($userId, $amount, 'TXN123456789', $nonce);
    check(DepositService::request($userId, $amount, 'TXN123456789', $nonce) === $requestId, 'Retry created a second manual deposit request.');
    check((int) WalletService::balance($userId)['balance_minor'] === $before, 'A pending deposit changed the wallet balance.');
    expect_exception(
        DomainException::class,
        fn() => DepositService::request($userId, $amount, 'TXN123456789', bin2hex(random_bytes(16)))
    );

    DepositService::review($requestId, $context['admin'], 'approved');
    check((int) WalletService::balance($userId)['balance_minor'] === $before + $amount, 'Approved deposit was not credited exactly once.');
    expect_exception(DomainException::class, fn() => DepositService::review($requestId, $context['admin'], 'approved'));

    $rejectedId = DepositService::request($userId, 5000, 'TXN987654321', bin2hex(random_bytes(16)));
    expect_exception(InvalidArgumentException::class, fn() => DepositService::review($rejectedId, $context['admin'], 'rejected'));
    DepositService::review($rejectedId, $context['admin'], 'rejected', 'UTR could not be verified.');
    check((int) WalletService::balance($userId)['balance_minor'] === $before + $amount, 'Rejected deposit altered the wallet.');
    $stmt = $db->prepare('SELECT status FROM deposit_requests WHERE id=?');
    $stmt->execute([$rejectedId]);
    check($stmt->fetchColumn() === 'rejected', 'Rejected deposit status was not recorded.');
});

run_case('admin wallet adjustments support audited credits and debits without duplicate effects', function () use ($db, $context): void {
    $userId = $context['adjustment'];
    $before = (int) WalletService::balance($userId)['balance_minor'];
    $creditNonce = bin2hex(random_bytes(16));
    check(AdminWalletService::adjust($context['admin'], $userId, 'credit', 12345, 'Manual prize correction', $creditNonce), 'Manual wallet credit did not post.');
    check(!AdminWalletService::adjust($context['admin'], $userId, 'credit', 12345, 'Manual prize correction', $creditNonce), 'An identical wallet retry was not treated as a duplicate.');
    check((int) WalletService::balance($userId)['balance_minor'] === $before + 12345, 'Admin credit was applied more than once.');
    expect_exception(
        DomainException::class,
        fn() => AdminWalletService::adjust($context['admin'], $userId, 'debit', 12345, 'Conflicting replay', $creditNonce)
    );

    $debitNonce = bin2hex(random_bytes(16));
    check(AdminWalletService::adjust($context['admin'], $userId, 'debit', 2345, 'Correction reversal', $debitNonce), 'Manual wallet debit did not post.');
    check((int) WalletService::balance($userId)['balance_minor'] === $before + 10000, 'Admin debit produced the wrong balance.');
    expect_exception(
        DomainException::class,
        fn() => AdminWalletService::adjust($context['admin'], $userId, 'debit', 50000000, 'Excess debit', bin2hex(random_bytes(16)))
    );
    expect_exception(
        DomainException::class,
        fn() => AdminWalletService::adjust($context['admin'], $context['admin'], 'credit', 100, 'Wrong target role', bin2hex(random_bytes(16)))
    );
    $stmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_id=? AND entity_type='user' AND entity_id=? AND action='wallet.admin_adjusted'");
    $stmt->execute([$context['admin'], $userId]);
    check((int) $stmt->fetchColumn() === 2, 'Wallet adjustment audit records are missing or duplicated.');
});

run_case('Solo registration works without a team and prevents duplicate joins and overbooking', function () use ($db, $context): void {
    $tournamentId = create_fixture_tournament($context['admin'], 'test-solo-capacity-' . bin2hex(random_bytes(3)), 'solo', 1, 500);
    $context['solo_tournament'] = $tournamentId;
    TournamentService::register($context['solo'], $tournamentId, null);
    check(TournamentService::occupiedSlots($tournamentId) === 1, 'Solo participant slot was not occupied.');
    expect_exception(DomainException::class, fn() => TournamentService::register($context['solo'], $tournamentId, null));
    expect_exception(DomainException::class, fn() => TournamentService::register($context['overflow'], $tournamentId, null));
    check(TournamentService::isParticipant($context['solo'], $tournamentId) !== null, 'Participant lookup failed.');
});

run_case('registration rejects late entries', function () use ($context): void {
    $tournamentId = create_fixture_tournament($context['admin'], 'test-late-' . bin2hex(random_bytes(3)), 'solo', 4, 0, -3600);
    expect_exception(DomainException::class, fn() => TournamentService::register($context['overflow'], $tournamentId, null));
});

run_case('Duo invitations, acceptance, exact roster checks, and team management work', function () use ($db, $context): void {
    $teamId = TeamService::create($context['duo_captain'], 'Test Duo ' . bin2hex(random_bytes(2)));
    $tournamentId = create_fixture_tournament($context['admin'], 'test-duo-' . bin2hex(random_bytes(3)), 'duo', 2, 300);
    expect_exception(DomainException::class, fn() => TournamentService::register($context['duo_captain'], $tournamentId, $teamId));
    $stmt = $db->prepare('SELECT email FROM users WHERE id=?');
    $stmt->execute([$context['duo_member']]);
    TeamService::invite($context['duo_captain'], $teamId, (string) $stmt->fetchColumn());
    $stmt = $db->prepare('SELECT id FROM team_invitations WHERE team_id=? AND invitee_id=?');
    $stmt->execute([$teamId, $context['duo_member']]);
    $invitationId = (int) $stmt->fetchColumn();
    TeamService::respond($context['duo_member'], $invitationId, 'accepted');
    expect_exception(DomainException::class, fn() => TeamService::respond($context['duo_member'], $invitationId, 'accepted'));
    $registrationId = TournamentService::register($context['duo_captain'], $tournamentId, $teamId);
    $stmt = $db->prepare('SELECT COUNT(*) FROM registration_members WHERE registration_id=?');
    $stmt->execute([$registrationId]);
    check((int) $stmt->fetchColumn() === 2, 'Duo did not register exactly two players.');
    expect_exception(DomainException::class, fn() => TeamService::removeMember($context['duo_captain'], $teamId, $context['duo_member']));
    check((int) WalletService::balance($context['duo_captain'])['balance_minor'] === 100000 - 300, 'Duo fee was not charged to the captain exactly once.');
});

run_case('Squad registration requires exactly four active players', function () use ($db, $context): void {
    $teamId = TeamService::create($context['squad_captain'], 'Test Squad ' . bin2hex(random_bytes(2)));
    $tournamentId = create_fixture_tournament($context['admin'], 'test-squad-' . bin2hex(random_bytes(3)), 'squad', 4, 0);
    foreach ([$context['squad_member_1'], $context['squad_member_2'], $context['squad_member_3']] as $memberId) {
        $stmt = $db->prepare('SELECT email FROM users WHERE id=?');
        $stmt->execute([$memberId]);
        TeamService::invite($context['squad_captain'], $teamId, (string) $stmt->fetchColumn());
        $stmt = $db->prepare('SELECT id FROM team_invitations WHERE team_id=? AND invitee_id=?');
        $stmt->execute([$teamId, $memberId]);
        TeamService::respond($memberId, (int) $stmt->fetchColumn(), 'accepted');
    }
    $registrationId = TournamentService::register($context['squad_captain'], $tournamentId, $teamId);
    $stmt = $db->prepare('SELECT COUNT(*) FROM registration_members WHERE registration_id=?');
    $stmt->execute([$registrationId]);
    check((int) $stmt->fetchColumn() === 4, 'Squad did not register four players.');
    check(TournamentService::occupiedSlots($tournamentId) === 4, 'Squad capacity was not counted by individual players.');
});

run_case('rank-and-kill formula, multi-match totals, and leaderboard ranking work', function () use (&$context): void {
    $tournamentId = create_fixture_tournament($context['admin'], 'test-score-' . bin2hex(random_bytes(3)), 'solo', 10, 0);
    $context['score_tournament'] = $tournamentId;
    $registrationA = TournamentService::register($context['score_a'], $tournamentId, null);
    $registrationB = TournamentService::register($context['score_b'], $tournamentId, null);
    $context['score_registration_a'] = $registrationA;
    $context['score_registration_b'] = $registrationB;
    ScoringService::saveRules($tournamentId, 2, [1=>12,2=>9,3=>8], ['total_points','total_kills','placement_points','best_rank','registration_time'], $context['admin']);
    ScoringService::saveResult($tournamentId, 1, $registrationA, 1, 5, 3, 1, $context['admin'], 'Room opens ten minutes before match time.');
    ScoringService::saveResult($tournamentId, 1, $registrationB, 2, 6, 0, 0, $context['admin']);
    ScoringService::saveResult($tournamentId, 2, $registrationA, 2, 1, 0, 0, $context['admin']);
    ScoringService::saveResult($tournamentId, 2, $registrationB, 1, 2, 0, 0, $context['admin']);
    $noteStmt = Database::connection()->prepare('SELECT note FROM matches WHERE tournament_id=? AND match_number=1');
    $noteStmt->execute([$tournamentId]);
    check($noteStmt->fetchColumn() === 'Room opens ten minutes before match time.', 'The optional match note was not saved.');
    $noteStmt = Database::connection()->prepare('SELECT note FROM matches WHERE tournament_id=? AND match_number=2');
    $noteStmt->execute([$tournamentId]);
    check($noteStmt->fetchColumn() === null, 'A blank match note should remain hidden.');
    $stmt = Database::connection()->prepare('SELECT placement_points,kill_points,total_points FROM results WHERE registration_id=? AND match_id=(SELECT id FROM matches WHERE tournament_id=? AND match_number=1)');
    $stmt->execute([$registrationA,$tournamentId]);
    $firstMatch = $stmt->fetch();
    check((int)$firstMatch['placement_points'] === 12 && (int)$firstMatch['kill_points'] === 10 && (int)$firstMatch['total_points'] === 24, 'Formula should be 12 + (5 × 2) + 3 − 1 = 24.');
    $rows = ScoringService::leaderboard($tournamentId);
    check((int)$rows[0]['registration_id'] === $registrationB && (int)$rows[0]['total_points'] === 37, 'Multi-match aggregate ranking was wrong.');
    check((int)$rows[0]['matches_played'] === 2 && (int)$rows[0]['total_kills'] === 8, 'Multi-match kills or match count did not aggregate.');
});

run_case('bulk results apply weighted formulas, distribute rank and kill prizes, and lock after payout', function () use ($db, $context): void {
    $tournamentId = create_fixture_tournament($context['admin'], 'test-bulk-payout-' . bin2hex(random_bytes(3)), 'solo', 2, 0);
    $db->prepare(
        'UPDATE tournaments
         SET prize_pool_minor=10000, auto_prizes_enabled=1,
             rank_prize_percentages=?, kill_reward_per_kill_minor=100
         WHERE id=?'
    )->execute([json_encode([1=>70,2=>30], JSON_THROW_ON_ERROR), $tournamentId]);
    $registrationA = TournamentService::register($context['score_a'], $tournamentId, null);
    $registrationB = TournamentService::register($context['score_b'], $tournamentId, null);
    ScoringService::saveRules(
        $tournamentId, 2, [1=>12,2=>9],
        ['total_points','total_kills','placement_points','best_rank','registration_time'],
        $context['admin'], 2, 3, 2
    );
    $rows = [
        ['registration_id'=>$registrationA,'placement_rank'=>1,'kills'=>2,'bonus_points'=>3,'penalty_points'=>1],
        ['registration_id'=>$registrationB,'placement_rank'=>2,'kills'=>1,'bonus_points'=>0,'penalty_points'=>0],
    ];
    expect_exception(DomainException::class, fn() => ScoringService::saveResultsBulk($tournamentId, 1, [$rows[0]], $context['admin']));
    check((int) $db->query('SELECT COUNT(*) FROM results WHERE tournament_id=' . (int) $tournamentId)->fetchColumn() === 0, 'Incomplete bulk submission wrote partial results.');
    check(ScoringService::saveResultsBulk($tournamentId, 1, $rows, $context['admin']) === 2, 'Bulk save did not report both entries.');
    $stmt = $db->prepare(
        'SELECT placement_points,kill_points,bonus_input,penalty_input,bonus_points,penalty_points,total_points
         FROM results WHERE tournament_id=? AND registration_id=?'
    );
    $stmt->execute([$tournamentId, $registrationA]);
    $result = $stmt->fetch();
    check(
        (int) $result['placement_points'] === 24 && (int) $result['kill_points'] === 4 &&
        (int) $result['bonus_input'] === 3 && (int) $result['penalty_input'] === 1 &&
        (int) $result['bonus_points'] === 9 && (int) $result['penalty_points'] === 2 &&
        (int) $result['total_points'] === 35,
        'Weighted formula inputs or total were calculated incorrectly.'
    );
    $balanceA = (int) WalletService::balance($context['score_a'])['balance_minor'];
    $balanceB = (int) WalletService::balance($context['score_b'])['balance_minor'];
    ScoringService::publish($tournamentId, 'final', $context['admin']);
    check((int) WalletService::balance($context['score_a'])['balance_minor'] === $balanceA + 6990, 'Rank and kill rewards for first place were not credited correctly.');
    check((int) WalletService::balance($context['score_b'])['balance_minor'] === $balanceB + 3010, 'Rank and kill rewards for second place were not credited correctly.');
    $stmt = $db->prepare('SELECT payouts_locked_at FROM tournaments WHERE id=?');
    $stmt->execute([$tournamentId]);
    check($stmt->fetchColumn() !== null, 'Payout timestamp was not recorded.');
    expect_exception(DomainException::class, fn() => ScoringService::saveResult($tournamentId, 1, $registrationA, 1, 3, 0, 0, $context['admin']));
    expect_exception(DomainException::class, fn() => ScoringService::saveRules($tournamentId, 2, [1=>10], ['total_points'], $context['admin']));
    expect_exception(DomainException::class, fn() => ScoringService::recalculate($tournamentId, $context['admin']));
    expect_exception(DomainException::class, fn() => ScoringService::publish($tournamentId, 'provisional', $context['admin']));
});

run_case('final result corrections become provisional and recalculation preserves totals', function () use (&$context): void {
    $tournamentId = $context['score_tournament'];
    $registrationB = $context['score_registration_b'];
    ScoringService::publish($tournamentId, 'final', $context['admin']);
    ScoringService::saveResult($tournamentId, 1, $registrationB, 2, 4, 0, 0, $context['admin']);
    $stmt = Database::connection()->prepare('SELECT results_status,status FROM tournaments WHERE id=?');
    $stmt->execute([$tournamentId]);
    $state = $stmt->fetch();
    check($state['results_status'] === 'provisional' && $state['status'] === 'live', 'Corrected final results were not returned to provisional state.');
    check(ScoringService::recalculate($tournamentId, $context['admin']) === 4, 'Expected four result rows to recalculate.');
    $rows = ScoringService::leaderboard($tournamentId);
    check((int)$rows[0]['registration_id'] === $context['score_registration_a'] && (int)$rows[0]['total_points'] === 35, 'Corrected final ordering or aggregate score was wrong.');
    $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='result.corrected' AND entity_type='result'");
    $stmt->execute();
    check((int)$stmt->fetchColumn() >= 1, 'Correction audit event is missing.');
});

run_case('cancelled tournament refunds the entry fee exactly once', function () use ($db, $context): void {
    $userId = $context['refunder'];
    $tournamentId = create_fixture_tournament($context['admin'], 'test-refund-' . bin2hex(random_bytes(3)), 'solo', 1, 750);
    $before = (int) WalletService::balance($userId)['balance_minor'];
    $registration = TournamentService::register($userId, $tournamentId, null);
    check((int) WalletService::balance($userId)['balance_minor'] === $before - 750, 'Entry fee debit was incorrect.');
    $db->prepare("UPDATE tournaments SET status='cancelled' WHERE id=?")->execute([$tournamentId]);
    check(TournamentService::refundRegistration($registration, $context['admin']), 'First cancellation refund was not processed.');
    check(!TournamentService::refundRegistration($registration, $context['admin']), 'Duplicate cancellation refund was not ignored.');
    check((int) WalletService::balance($userId)['balance_minor'] === $before, 'Cancellation did not restore the entry fee exactly once.');
});

run_case('withdrawal reservations, rejection release, and confirmed payout references are enforced', function () use ($db, $context): void {
    $userId = $context['withdrawal'];
    $before = WalletService::balance($userId);
    $nonce = bin2hex(random_bytes(16));
    $requestId = WithdrawalService::request($userId, 20000, 'testplayer@upi', $nonce);
    check(WithdrawalService::request($userId, 20000, 'testplayer@upi', $nonce) === $requestId, 'Duplicate withdrawal form created a second request.');
    $reserved = WalletService::balance($userId);
    check((int)$reserved['balance_minor'] === (int)$before['balance_minor'] - 20000 && (int)$reserved['reserved_minor'] === 20000, 'Withdrawal did not reserve funds.');
    $stmt = $db->prepare('SELECT gst_minor,net_amount_minor FROM withdrawal_requests WHERE id=?');
    $stmt->execute([$requestId]);
    $taxBreakdown = $stmt->fetch();
    check((int)$taxBreakdown['gst_minor'] === 3600 && (int)$taxBreakdown['net_amount_minor'] === 16400, 'Withdrawal GST or net transfer amount was incorrect.');
    [$encrypted, $iv, $tag] = encrypt_private_value('testplayer@upi');
    check(WithdrawalService::revealUpi(['payout_details_enc'=>$encrypted, 'payout_details_iv'=>$iv, 'payout_details_tag'=>$tag]) === 'testplayer@upi', 'Encrypted withdrawal destination was not readable.');
    expect_exception(DomainException::class, fn() => WithdrawalService::review($requestId,$context['admin'],'paid',''));
    WithdrawalService::review($requestId,$context['admin'],'approved');
    WithdrawalService::review($requestId,$context['admin'],'rejected','Test rejection reason');
    check((int)WalletService::balance($userId)['balance_minor'] === (int)$before['balance_minor'] && (int)WalletService::balance($userId)['reserved_minor'] === 0, 'Rejected withdrawal did not release funds once.');
    expect_exception(DomainException::class, fn() => WithdrawalService::review($requestId,$context['admin'],'rejected','Again'));

    $secondId = WithdrawalService::request($userId,15000,'testplayer@upi',bin2hex(random_bytes(16)));
    WithdrawalService::review($secondId,$context['admin'],'approved');
    WithdrawalService::review($secondId,$context['admin'],'processing');
    expect_exception(InvalidArgumentException::class, fn() => WithdrawalService::review($secondId,$context['admin'],'paid',''));
    WithdrawalService::review($secondId,$context['admin'],'paid','PAYOUT-TEST-REFERENCE-01');
    $stmt = $db->prepare('SELECT status,payout_reference FROM withdrawal_requests WHERE id=?');
    $stmt->execute([$secondId]);
    $row = $stmt->fetch();
    check($row['status'] === 'paid' && $row['payout_reference'] === 'PAYOUT-TEST-REFERENCE-01', 'Paid status did not require/store a payout reference.');
    check((int)WalletService::balance($userId)['reserved_minor'] === 0, 'Paid withdrawal left funds reserved.');
});

run_case('PWA and mobile layout contain responsive viewport, navigation, manifest, and PNG icons', function (): void {
    $layout = file_get_contents(dirname(__DIR__) . '/views/layout.php');
    $css = file_get_contents(dirname(__DIR__) . '/src/app.css');
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/public/manifest.webmanifest'), true, 16, JSON_THROW_ON_ERROR);
    check(str_contains((string)$layout, 'name="viewport"') && str_contains((string)$layout, 'md:hidden'), 'Viewport or mobile bottom navigation is missing.');
    check(str_contains((string)$css, '@media') || str_contains((string)$css, 'md:'), 'Responsive styles are missing.');
    foreach ($manifest['icons'] as $icon) {
        check(is_file(dirname(__DIR__) . '/public/' . str_replace('./','',$icon['src'])), 'PWA icon file is missing.');
    }
    check($manifest['display'] === 'standalone', 'PWA standalone display is missing.');
});

echo "\nTest summary: $testsPassed passed, $testsFailed failed.\n";
exit($testsFailed === 0 ? 0 : 1);

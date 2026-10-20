<?php
declare(strict_types=1);

function paged_rows(string $selectSql, string $countSql, array $params, int $pageNumber = 1, int $perPage = 20): array
{
    $db = Database::connection();
    $count = $db->prepare($countSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageCount = max(1, (int) ceil($total / $perPage));
    $pageNumber = max(1, min($pageNumber, $pageCount));
    $offset = ($pageNumber - 1) * $perPage;
    $stmt = $db->prepare($selectSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    return [$stmt->fetchAll(), $total, $pageCount, $pageNumber];
}

function query_tournaments(string $where, array $params, int $limit = 20, int $offset = 0): array
{
    $stmt = Database::connection()->prepare(
        'SELECT t.*,
          (SELECT COUNT(*) FROM registration_members rm JOIN registrations r ON r.id=rm.registration_id
           WHERE rm.tournament_id=t.id AND r.status=\'confirmed\') AS occupied_slots
         FROM tournaments t ' . $where . ' ORDER BY t.match_time ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function render_page(): array
{
    $db = Database::connection();
    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
    $current = current_user();
    $contentFile = '';
    $pageTitle = 'Victory Yudh';

    switch ($page) {
        case 'home': {
            $modeFilter = is_string($_GET['mode'] ?? null) ? $_GET['mode'] : 'all';
            if (!in_array($modeFilter, ['all','solo','duo','squad'], true)) {
                $modeFilter = 'all';
            }
            $where = "WHERE t.status IN ('open','live','locked') AND t.match_time>=UTC_TIMESTAMP()";
            $params = [];
            if ($modeFilter !== 'all') {
                $where .= ' AND t.mode=?';
                $params[] = $modeFilter;
            }
            $tournaments = query_tournaments($where, $params, 6);
            $stats = [
                'upcoming' => (int) $db->query("SELECT COUNT(*) FROM tournaments WHERE status IN ('open','locked') AND match_time>=UTC_TIMESTAMP()")->fetchColumn(),
                'players' => (int) $db->query("SELECT COUNT(*) FROM users WHERE status='active' AND role='user'")->fetchColumn(),
            ];
            $announcements = $db->query(
                "SELECT title, body FROM announcements WHERE status='published'
                 ORDER BY published_at DESC, id DESC LIMIT 4"
            )->fetchAll();
            $pageTitle = 'Free Fire tournaments & leaderboard';
            $contentFile = 'home';
            break;
        }
        case 'tournaments': {
            $search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
            $modeFilter = is_string($_GET['mode'] ?? null) ? $_GET['mode'] : 'all';
            $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
            if (!in_array($modeFilter, ['all','solo','duo','squad'], true)) $modeFilter = 'all';
            if (!in_array($statusFilter, ['all','open','locked','live','completed'], true)) $statusFilter = 'all';
            $clauses = [];
            $params = [];
            if ($statusFilter === 'all') {
                $clauses[] = "t.status IN ('open','locked','live','completed')";
            } else {
                $clauses[] = 't.status=?';
                $params[] = $statusFilter;
            }
            if ($modeFilter !== 'all') {
                $clauses[] = 't.mode=?';
                $params[] = $modeFilter;
            }
            if ($search !== '') {
                $clauses[] = '(t.title LIKE ? OR t.map_name LIKE ?)';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }
            $where = 'WHERE ' . implode(' AND ', $clauses);
            $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
            [$total, $pageCount] = (function () use ($db, $where, $params): array {
                $stmt = $db->prepare('SELECT COUNT(*) FROM tournaments t ' . $where);
                $stmt->execute($params);
                $count = (int) $stmt->fetchColumn();
                return [$count, max(1, (int) ceil($count / 12))];
            })();
            $pageNumber = min($pageNumber, $pageCount);
            $tournaments = query_tournaments($where, $params, 12, ($pageNumber - 1) * 12);
            $pageTitle = 'Browse tournaments';
            $contentFile = 'tournaments';
            break;
        }
        case 'tournament': {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            $stmt = $db->prepare('SELECT * FROM tournaments WHERE id=?');
            $stmt->execute([$id ?: 0]);
            $tournament = $stmt->fetch();
            if (!$tournament) {
                return ['not_found' => true];
            }
            $occupied = TournamentService::occupiedSlots((int) $tournament['id']);
            $registration = $current ? TournamentService::isParticipant((int) $current['id'], (int) $tournament['id']) : null;
            $myTeams = [];
            if ($current && $tournament['mode'] !== 'solo') {
                $stmt = $db->prepare(
                    'SELECT t.id, t.name, t.captain_id, COUNT(tm.user_id) AS member_count
                     FROM teams t JOIN team_members captain ON captain.team_id=t.id AND captain.user_id=t.captain_id
                     JOIN team_members tm ON tm.team_id=t.id
                     WHERE t.captain_id=? GROUP BY t.id ORDER BY t.name'
                );
                $stmt->execute([$current['id']]);
                $myTeams = $stmt->fetchAll();
            }
            $roomAvailable = $registration
                && $tournament['status'] !== 'cancelled'
                && strtotime((string) $tournament['room_release_at'] . ' UTC') <= time();
            $roomId = $roomPassword = '';
            if ($roomAvailable) {
                $roomId = decrypt_private_value($tournament['room_id_enc'], $tournament['room_id_iv'], $tournament['room_id_tag']);
                $roomPassword = decrypt_private_value($tournament['room_password_enc'], $tournament['room_password_iv'], $tournament['room_password_tag']);
            }
            $stmt = $db->prepare(
                'SELECT points_per_kill, placement_multiplier, bonus_multiplier, penalty_multiplier
                 FROM scoring_rules WHERE tournament_id=? AND placement_rank=0 AND active=1'
            );
            $stmt->execute([$tournament['id']]);
            $scoringFormula = $stmt->fetch() ?: [
                'points_per_kill' => 0, 'placement_multiplier' => 1, 'bonus_multiplier' => 1, 'penalty_multiplier' => 1,
            ];
            $pageTitle = $tournament['title'];
            $contentFile = 'tournament';
            break;
        }
        case 'login':
        case 'register': {
            if ($current) {
                redirect('index.php?page=dashboard');
            }
            $authMode = $page;
            $pageTitle = $page === 'register' ? 'Create your player account' : 'Log in';
            $contentFile = 'auth';
            break;
        }
        case 'admin-login': {
            if (has_admin_role($current)) {
                redirect('index.php?page=admin');
            }
            $pageTitle = 'Admin sign in';
            $contentFile = 'admin-login';
            break;
        }
        case 'dashboard': {
            $current = require_auth();
            $wallet = WalletService::balance((int) $current['id']);
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM registration_members rm JOIN registrations r ON r.id=rm.registration_id
                 WHERE rm.user_id=? AND r.status='confirmed'"
            );
            $stmt->execute([$current['id']]);
            $joined = (int) $stmt->fetchColumn();
            $stmt = $db->prepare(
                "SELECT DISTINCT r.tournament_id FROM registration_members rm JOIN registrations r ON r.id=rm.registration_id
                 WHERE rm.user_id=? AND r.status='confirmed' ORDER BY r.tournament_id DESC LIMIT 30"
            );
            $stmt->execute([$current['id']]);
            $bestRank = null;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tournamentId) {
                foreach (ScoringService::leaderboard((int) $tournamentId) as $result) {
                    $memberCheck = $db->prepare('SELECT 1 FROM registration_members WHERE registration_id=? AND user_id=?');
                    $memberCheck->execute([$result['registration_id'], $current['id']]);
                    if ($memberCheck->fetchColumn()) {
                        $bestRank = $bestRank === null ? (int) $result['position'] : min($bestRank, (int) $result['position']);
                        break;
                    }
                }
            }
            $stmt = $db->prepare(
                "SELECT r.tournament_id, t.title, t.mode, t.match_time, r.status
                 FROM registration_members rm JOIN registrations r ON r.id=rm.registration_id
                 JOIN tournaments t ON t.id=r.tournament_id
                 WHERE rm.user_id=? ORDER BY t.match_time DESC LIMIT 8"
            );
            $stmt->execute([$current['id']]);
            $registrations = $stmt->fetchAll();
            $stmt = $db->prepare(
                "SELECT res.kills, res.total_points, m.match_number, t.id AS tournament_id, t.title
                 FROM registration_members rm JOIN registrations reg ON reg.id=rm.registration_id
                 JOIN results res ON res.registration_id=reg.id
                 JOIN matches m ON m.id=res.match_id
                 JOIN tournaments t ON t.id=res.tournament_id
                 WHERE rm.user_id=? ORDER BY res.updated_at DESC LIMIT 5"
            );
            $stmt->execute([$current['id']]);
            $matchHistory = $stmt->fetchAll();
            $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 4');
            $stmt->execute([$current['id']]);
            $recentNotifications = $stmt->fetchAll();
            $summary = ['joined' => $joined, 'best_rank' => $bestRank];
            $pageTitle = 'Player dashboard';
            $contentFile = 'dashboard';
            break;
        }
        case 'wallet': {
            $current = require_auth();
            $wallet = WalletService::balance((int) $current['id']);
            $transactionPage = max(1, (int) ($_GET['p'] ?? 1));
            $stmt = $db->prepare('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?');
            $stmt->execute([$current['id']]);
            $transactionCount = (int) $stmt->fetchColumn();
            $transactionPages = max(1, (int) ceil($transactionCount / 12));
            $transactionPage = min($transactionPage, $transactionPages);
            $stmt = $db->prepare('SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY id DESC LIMIT 12 OFFSET ' . (($transactionPage - 1) * 12));
            $stmt->execute([$current['id']]);
            $transactions = $stmt->fetchAll();
            $stmt = $db->prepare('SELECT * FROM withdrawal_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 8');
            $stmt->execute([$current['id']]);
            $withdrawals = $stmt->fetchAll();
            $stmt = $db->prepare('SELECT * FROM deposit_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 8');
            $stmt->execute([$current['id']]);
            $depositRequests = $stmt->fetchAll();
            if (!isset($_SESSION['_deposit_nonce'])) {
                $_SESSION['_deposit_nonce'] = bin2hex(random_bytes(16));
            }
            $depositNonce = $_SESSION['_deposit_nonce'];
            if (!isset($_SESSION['_withdraw_nonce'])) {
                $_SESSION['_withdraw_nonce'] = bin2hex(random_bytes(16));
            }
            $withdrawNonce = $_SESSION['_withdraw_nonce'];
            $pageTitle = 'Wallet & transactions';
            $contentFile = 'wallet';
            break;
        }
        case 'team': {
            $current = require_auth();
            $stmt = $db->prepare(
                'SELECT t.id, t.name, t.captain_id, COUNT(tm.user_id) AS member_count
                 FROM team_members mine JOIN teams t ON t.id=mine.team_id
                 JOIN team_members tm ON tm.team_id=t.id
                 WHERE mine.user_id=? GROUP BY t.id ORDER BY t.created_at DESC'
            );
            $stmt->execute([$current['id']]);
            $teams = $stmt->fetchAll();
            foreach ($teams as &$team) {
                $stmt = $db->prepare(
                    'SELECT tm.user_id, tm.member_role, p.display_name, u.email
                     FROM team_members tm JOIN users u ON u.id=tm.user_id
                     LEFT JOIN profiles p ON p.user_id=u.id WHERE tm.team_id=? ORDER BY tm.member_role DESC, tm.joined_at'
                );
                $stmt->execute([$team['id']]);
                $team['members'] = $stmt->fetchAll();
            }
            unset($team);
            $stmt = $db->prepare(
                "SELECT ti.id, ti.expires_at, t.name AS team_name, p.display_name AS inviter_name
                 FROM team_invitations ti JOIN teams t ON t.id=ti.team_id
                 JOIN profiles p ON p.user_id=ti.inviter_id
                 WHERE ti.invitee_id=? AND ti.status='pending' AND ti.expires_at>UTC_TIMESTAMP()
                 ORDER BY ti.invited_at DESC"
            );
            $stmt->execute([$current['id']]);
            $invitations = $stmt->fetchAll();
            $pageTitle = 'Teams & invitations';
            $contentFile = 'team';
            break;
        }
        case 'profile': {
            $current = require_auth();
            $stmt = $db->prepare(
                'SELECT u.email, p.display_name, p.free_fire_uid FROM users u
                 JOIN profiles p ON p.user_id=u.id WHERE u.id=?'
            );
            $stmt->execute([$current['id']]);
            $profile = $stmt->fetch() ?: ['email' => $current['email'], 'display_name' => '', 'free_fire_uid' => ''];
            $pageTitle = 'Player profile';
            $contentFile = 'profile';
            break;
        }
        case 'notifications': {
            $current = require_auth();
            $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');
            $stmt->execute([$current['id']]);
            $unreadCount = (int) $stmt->fetchColumn();
            $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 60');
            $stmt->execute([$current['id']]);
            $notifications = $stmt->fetchAll();
            $pageTitle = 'Notifications';
            $contentFile = 'notifications';
            break;
        }
        case 'leaderboard': {
            $tournamentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$tournamentId) {
                $availableTournaments = $db->query(
                    "SELECT id, title, mode, results_status FROM tournaments
                     WHERE results_status IN ('provisional','final') ORDER BY results_published_at DESC LIMIT 30"
                )->fetchAll();
                $tournament = null;
                $rows = [];
                $matchNotes = [];
            } else {
                $stmt = $db->prepare("SELECT * FROM tournaments WHERE id=? AND results_status IN ('provisional','final')");
                $stmt->execute([$tournamentId]);
                $tournament = $stmt->fetch();
                if (!$tournament) {
                    return ['not_found' => true];
                }
                $availableTournaments = [];
                $rows = ScoringService::leaderboard((int) $tournamentId);
                $stmt = $db->prepare(
                    "SELECT m.id AS match_id,m.match_number,m.note,m.status,
                            r.registration_id,r.placement_rank,r.kills,r.total_points,
                            COALESCE(t.name,p.display_name,u.email) AS entry_name
                     FROM matches m JOIN results r ON r.match_id=m.id
                     JOIN registrations reg ON reg.id=r.registration_id
                     LEFT JOIN teams t ON t.id=reg.team_id
                     JOIN users u ON u.id=reg.captain_id
                     LEFT JOIN profiles p ON p.user_id=u.id
                     WHERE m.tournament_id=? AND reg.status='confirmed'
                     ORDER BY m.match_number,r.placement_rank,r.total_points DESC"
                );
                $stmt->execute([$tournamentId]);
                $matchResults = $stmt->fetchAll();
                $stmt = $db->prepare(
                    "SELECT match_number,note FROM matches
                     WHERE tournament_id=? AND status='completed' AND note IS NOT NULL AND TRIM(note)<>''
                     ORDER BY match_number"
                );
                $stmt->execute([$tournamentId]);
                $matchNotes = $stmt->fetchAll();
            }
            $pageTitle = 'Leaderboard';
            $contentFile = 'leaderboard';
            break;
        }
        case 'support':
        case 'faq':
        case 'terms':
        case 'privacy':
        case 'refund-policy': {
            $contentPage = $page;
            $supportEmail = null;
            $eyebrows = ['support'=>'Player support','faq'=>'Arena help','terms'=>'Rules of play','privacy'=>'Your information','refund-policy'=>'Wallet & refunds'];
            $headings = ['support'=>'How can we help?','faq'=>'Frequently asked questions','terms'=>'Terms of service','privacy'=>'Privacy policy','refund-policy'=>'Refund policy'];
            $eyebrow = $eyebrows[$page];
            $heading = $headings[$page];
            $contentHtml = match ($page) {
                'faq' => '<h2>How do I join a tournament?</h2><p>Create an account, open a tournament with registration open, and register. Solo entries do not need a team. Duo and Squad entries require a captain with exactly the listed player count and enough wallet balance for the fee.</p><h2>How are tournament scores calculated?</h2><p>For each match: placement points + (kills × configured points per kill) + bonuses − penalties. Match totals are aggregated and ranked using the tournament’s published tie-breaker order. Admins enter or import the source results; Victory Yudh does not claim live access to Free Fire results.</p><h2>When do room details appear?</h2><p>Room ID and password are visible only to registered players after the tournament’s configured release time. Do not share room details outside your entry.</p><h2>When is a deposit credited?</h2><p>Submit the amount and transaction ID or UTR after paying to the displayed admin QR code. The wallet is credited only after an administrator verifies and approves the request.</p><h2>When is a withdrawal paid?</h2><p>Requests pass through Pending, Approved, Processing, Paid, or Rejected. Funds are reserved at request time. 18% GST is deducted from each requested amount. “Paid” is recorded only after the administrator has transferred the net amount and entered a confirmed reference.</p>',
                'terms' => '<h2>Eligibility and fair play</h2><p>Use an account and payment method you are authorized to use. Follow the rules listed on each tournament page, join with the registered players, and do not distribute private room credentials.</p><h2>Match results</h2><p>Results are entered or imported by authorized tournament administrators from match data they have collected. The leaderboard displays calculated results and may be provisional until an admin finalizes them. Corrections are audited.</p><h2>Wallet and prizes</h2><p>Wallet balances are maintained in INR minor units. Tournament entry fees are debited when a registration is confirmed. Prize credits are posted by an administrator and appear in the ledger. Withdrawal requests are reviewed and are not a guarantee of payment.</p><h2>Platform status</h2><p>Victory Yudh is an independent community tournament platform and is not affiliated with or endorsed by Garena. Tournament availability and eligibility are subject to applicable law, provider rules, and administrator decisions.</p><h2>Contact</h2><p>Use the in-app support form to report account, entry, result, or wallet issues. Do not send passwords or payment credentials.</p>',
                'privacy' => '<h2>Information we store</h2><p>Account email, player name, optional Free Fire UID, team memberships, tournament entries, match results, wallet ledger entries, deposit transaction IDs or UTRs, support requests, and operational audit events are stored to provide the service.</p><h2>Wallet data</h2><p>Deposits are reviewed manually using the submitted amount and transaction reference. UPI destinations submitted for withdrawals are encrypted at rest and shown only to administrators who review payouts.</p><h2>Security and access</h2><p>Passwords are stored as password hashes. Administrative actions are restricted and audited. Tournament room credentials are encrypted at rest and released only to registered players after the configured time.</p><h2>Retention and requests</h2><p>Financial, payment, and tournament records may be retained as needed for reconciliation, disputes, security, and applicable legal requirements. Contact support to request correction or account assistance.</p>',
                'refund-policy' => '<h2>Cancelled tournaments</h2><p>When an administrator cancels a tournament, confirmed entry fees are credited back to the wallet of the registering captain. The refund is recorded once in the immutable ledger.</p><h2>Deposits</h2><p>Deposit requests stay pending until an administrator verifies the transaction reference. Approved requests are credited to the wallet; rejected requests are not credited. Contact support if you believe a request was reviewed incorrectly.</p><h2>Withdrawals</h2><p>Withdrawal requests reserve funds. 18% GST is deducted from the requested amount. Rejected requests return the full reservation to available balance. Approved and Processing requests are not marked Paid until an administrator records a confirmed payout reference for the net amount.</p><h2>Need help?</h2><p>Submit an in-app support request with your transaction or tournament reference. Do not include passwords, API keys, card numbers, or other payment secrets.</p>',
                default => '<p>Describe your issue in the secure form below. Our support team will review the request inside the admin inbox.</p><p>Never include account passwords, full payment card details, API keys, or payment provider secrets.</p>',
            };
            $pageTitle = $heading;
            $contentFile = 'content';
            break;
        }
        case 'admin':
        case 'admin-tournaments':
        case 'admin-tournament-edit':
        case 'admin-room':
        case 'admin-results':
        case 'admin-users':
        case 'admin-teams':
        case 'admin-wallet':
        case 'admin-withdrawals':
        case 'admin-announcements':
        case 'admin-notifications':
        case 'admin-support':
        case 'admin-audit':
        case 'admin-settings': {
            $current = require_admin();
            $pageTitle = 'Admin · ' . ucwords(str_replace(['admin-','-'], ['', ' '], $page));
            if ($page === 'admin') {
                $stats = [
                    'users' => (int) $db->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn(),
                    'open_tournaments' => (int) $db->query("SELECT COUNT(*) FROM tournaments WHERE status='open'")->fetchColumn(),
                    'registrations' => (int) $db->query("SELECT COUNT(*) FROM registrations WHERE status='confirmed'")->fetchColumn(),
                    'pending_withdrawals' => (int) $db->query("SELECT COUNT(*) FROM withdrawal_requests WHERE status IN ('pending','approved','processing')")->fetchColumn(),
                    'manual_deposits' => (int) $db->query("SELECT COALESCE(SUM(amount_minor),0) FROM deposit_requests WHERE status='approved'")->fetchColumn(),
                    'support' => (int) $db->query("SELECT COUNT(*) FROM support_requests WHERE status IN ('open','in_progress')")->fetchColumn(),
                    'unpublished_results' => (int) $db->query("SELECT COUNT(*) FROM tournaments WHERE results_status='provisional'")->fetchColumn(),
                    'pending_deposits' => (int) $db->query("SELECT COUNT(*) FROM deposit_requests WHERE status='pending'")->fetchColumn(),
                ];
                $recentRegistrations = $db->query(
                    "SELECT r.created_at,t.title,t.mode,COALESCE(p.display_name,u.email) AS display_name
                     FROM registrations r JOIN tournaments t ON t.id=r.tournament_id
                     JOIN users u ON u.id=r.captain_id LEFT JOIN profiles p ON p.user_id=u.id
                     ORDER BY r.created_at DESC LIMIT 6"
                )->fetchAll();
                $recentWithdrawals = $db->query(
                    "SELECT wr.id,wr.amount_minor,wr.status,COALESCE(p.display_name,u.email) AS display_name
                     FROM withdrawal_requests wr JOIN users u ON u.id=wr.user_id
                     LEFT JOIN profiles p ON p.user_id=u.id ORDER BY wr.created_at DESC LIMIT 5"
                )->fetchAll();
                $recentAudit = $db->query(
                    'SELECT a.action,a.entity_type,a.entity_id,a.created_at,u.email
                     FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id
                     ORDER BY a.created_at DESC LIMIT 8'
                )->fetchAll();
                $contentFile = 'admin/dashboard';
            } elseif ($page === 'admin-tournaments') {
                $search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
                $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
                if (!in_array($statusFilter, ['all','draft','open','locked','live','completed','cancelled'], true)) $statusFilter = 'all';
                $where = 'WHERE 1=1';
                $params = [];
                if ($statusFilter !== 'all') { $where .= ' AND t.status=?'; $params[] = $statusFilter; }
                if ($search !== '') { $where .= ' AND (t.title LIKE ? OR t.map_name LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
                $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
                $select = 'SELECT t.*,(SELECT COUNT(*) FROM registration_members rm JOIN registrations r ON r.id=rm.registration_id WHERE rm.tournament_id=t.id AND r.status=\'confirmed\') AS occupied_slots FROM tournaments t ' . $where . ' ORDER BY t.match_time DESC';
                [$tournaments, $total, $pageCount, $pageNumber] = paged_rows($select, 'SELECT COUNT(*) FROM tournaments t ' . $where, $params, $pageNumber);
                $contentFile = 'admin/tournaments';
            } elseif ($page === 'admin-tournament-edit') {
                $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                $tournament = null;
                $scoring = [
                    'points_per_kill' => 1, 'placement_multiplier' => 1, 'bonus_multiplier' => 1, 'penalty_multiplier' => 1,
                    'placement_text' => "1=12\n2=9\n3=8\n4=7\n5=6\n6=5\n7=4\n8=3\n9=2\n10=1",
                    'tie_breakers' => ['total_points','total_kills','placement_points','best_rank','registration_time'],
                    'auto_prizes_enabled' => false, 'rank_prize_text' => "1=50\n2=30\n3=20",
                    'kill_reward_per_kill' => '0.00',
                ];
                if ($id) {
                    $stmt = $db->prepare('SELECT * FROM tournaments WHERE id=?');
                    $stmt->execute([$id]);
                    $tournament = $stmt->fetch();
                    if (!$tournament) return ['not_found' => true];
                    $stmt = $db->prepare(
                        'SELECT placement_rank, placement_points, points_per_kill, placement_multiplier,
                                bonus_multiplier, penalty_multiplier, tie_breakers
                         FROM scoring_rules WHERE tournament_id=? AND active=1 ORDER BY placement_rank'
                    );
                    $stmt->execute([$id]);
                    $rules = $stmt->fetchAll();
                    if ($rules) {
                        $scoring['points_per_kill'] = (int) $rules[0]['points_per_kill'];
                        $scoring['placement_multiplier'] = (int) $rules[0]['placement_multiplier'];
                        $scoring['bonus_multiplier'] = (int) $rules[0]['bonus_multiplier'];
                        $scoring['penalty_multiplier'] = (int) $rules[0]['penalty_multiplier'];
                        $placementRules = array_filter($rules, static fn(array $rule): bool => (int) $rule['placement_rank'] > 0);
                        if ($placementRules) {
                            $scoring['placement_text'] = implode("\n", array_map(fn($rule) => $rule['placement_rank'] . '=' . $rule['placement_points'], $placementRules));
                        }
                    }
                    $scoring['tie_breakers'] = ScoringService::decodeTieBreakers((string) ($rules[0]['tie_breakers'] ?? null));
                    $prizePercentages = json_decode((string) ($tournament['rank_prize_percentages'] ?? ''), true);
                    if (is_array($prizePercentages)) {
                        $scoring['rank_prize_text'] = implode("\n", array_map(
                            static fn($rank, $percent): string => $rank . '=' . $percent,
                            array_keys($prizePercentages),
                            array_values($prizePercentages)
                        ));
                    } else {
                        $scoring['rank_prize_text'] = '';
                    }
                    $scoring['auto_prizes_enabled'] = (bool) $tournament['auto_prizes_enabled'];
                    $scoring['kill_reward_per_kill'] = format_money_input((int) $tournament['kill_reward_per_kill_minor']);
                }
                $contentFile = 'admin/tournament-edit';
            } elseif ($page === 'admin-room') {
                $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                $stmt = $db->prepare('SELECT * FROM tournaments WHERE id=?');
                $stmt->execute([$id ?: 0]);
                $tournament = $stmt->fetch();
                if (!$tournament) return ['not_found' => true];
                $roomId = decrypt_private_value($tournament['room_id_enc'], $tournament['room_id_iv'], $tournament['room_id_tag']);
                $roomPassword = decrypt_private_value($tournament['room_password_enc'], $tournament['room_password_iv'], $tournament['room_password_tag']);
                $contentFile = 'admin/room';
            } elseif ($page === 'admin-results') {
                $tournamentOptions = $db->query(
                    'SELECT id,title,mode,results_status,prize_pool_minor,auto_prizes_enabled,
                            rank_prize_percentages,kill_reward_per_kill_minor,payouts_locked_at
                     FROM tournaments ORDER BY match_time DESC LIMIT 100'
                )->fetchAll();
                $requested = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                $selectedTournament = null;
                foreach ($tournamentOptions as $option) {
                    if ($requested && (int) $option['id'] === (int) $requested) {
                        $selectedTournament = $option;
                        break;
                    }
                }
                if (!$selectedTournament && $tournamentOptions) {
                    $selectedTournament = $tournamentOptions[0];
                }
                $registrations = $leaderboard = [];
                $pointsPerKill = 1;
                $formulaSettings = [
                    'points_per_kill' => 1, 'placement_multiplier' => 1, 'bonus_multiplier' => 1,
                    'penalty_multiplier' => 1, 'placement_text' => "1=12\n2=9\n3=8\n4=7\n5=6",
                    'tie_breakers' => ['total_points','total_kills','placement_points','best_rank','registration_time'],
                ];
                $activeMatchNumber = 1;
                $existingMatchResults = [];
                $currentMatchNote = '';
                $registrationParticipants = [];
                if ($selectedTournament) {
                    $stmt = $db->prepare(
                        'SELECT r.id,r.captain_id,t.name AS team_name,p.display_name,COUNT(rm.user_id) AS member_count
                         FROM registrations r LEFT JOIN teams t ON t.id=r.team_id
                         JOIN users captain ON captain.id=r.captain_id
                         LEFT JOIN profiles p ON p.user_id=captain.id
                         JOIN registration_members rm ON rm.registration_id=r.id
                         WHERE r.tournament_id=? AND r.status=\'confirmed\'
                         GROUP BY r.id,r.captain_id,t.name,p.display_name ORDER BY r.created_at'
                    );
                    $stmt->execute([$selectedTournament['id']]);
                    $registrations = $stmt->fetchAll();
                    $stmt = $db->prepare(
                        'SELECT rm.registration_id,u.id AS user_id,u.email AS username,p.display_name AS ign
                         FROM registration_members rm
                         JOIN registrations r ON r.id=rm.registration_id AND r.tournament_id=rm.tournament_id
                         JOIN users u ON u.id=rm.user_id
                         LEFT JOIN profiles p ON p.user_id=u.id
                         WHERE rm.tournament_id=? AND r.status=\'confirmed\'
                         ORDER BY rm.registration_id,u.email'
                    );
                    $stmt->execute([$selectedTournament['id']]);
                    foreach ($stmt->fetchAll() as $participant) {
                        $registrationParticipants[(int) $participant['registration_id']][] = $participant;
                    }
                    foreach ($registrations as &$entry) {
                        $entry['entry_name'] = $entry['team_name'] ?: ($entry['display_name'] ?: 'Player');
                        $entry['players'] = $registrationParticipants[(int) $entry['id']] ?? [];
                    }
                    unset($entry);
                    $leaderboard = ScoringService::leaderboard((int) $selectedTournament['id']);
                    foreach ($leaderboard as &$standing) {
                        $standing['players'] = $registrationParticipants[(int) $standing['registration_id']] ?? [];
                    }
                    unset($standing);
                    $stmt = $db->prepare(
                        'SELECT placement_rank,placement_points,points_per_kill,placement_multiplier,
                                bonus_multiplier,penalty_multiplier,tie_breakers
                         FROM scoring_rules WHERE tournament_id=? AND active=1 ORDER BY placement_rank'
                    );
                    $stmt->execute([$selectedTournament['id']]);
                    $resultRules = $stmt->fetchAll();
                    if ($resultRules) {
                        $formulaSettings['points_per_kill'] = (int) $resultRules[0]['points_per_kill'];
                        $formulaSettings['placement_multiplier'] = (int) $resultRules[0]['placement_multiplier'];
                        $formulaSettings['bonus_multiplier'] = (int) $resultRules[0]['bonus_multiplier'];
                        $formulaSettings['penalty_multiplier'] = (int) $resultRules[0]['penalty_multiplier'];
                        $placementRules = array_filter($resultRules, static fn(array $rule): bool => (int) $rule['placement_rank'] > 0);
                        if ($placementRules) {
                            $formulaSettings['placement_text'] = implode("\n", array_map(
                                fn($rule) => $rule['placement_rank'] . '=' . $rule['placement_points'],
                                $placementRules
                            ));
                        }
                        $formulaSettings['tie_breakers'] = ScoringService::decodeTieBreakers((string) $resultRules[0]['tie_breakers']);
                    }
                    $pointsPerKill = $formulaSettings['points_per_kill'];
                    $stmt = $db->prepare('SELECT COALESCE(MAX(match_number),0)+1 FROM matches WHERE tournament_id=?');
                    $stmt->execute([$selectedTournament['id']]);
                    $nextMatchNumber = min(100, (int) $stmt->fetchColumn());
                    $requestedMatchNumber = filter_var($_GET['match_number'] ?? null, FILTER_VALIDATE_INT);
                    $activeMatchNumber = $requestedMatchNumber !== false && $requestedMatchNumber >= 1 && $requestedMatchNumber <= 100
                        ? $requestedMatchNumber
                        : $nextMatchNumber;
                    $stmt = $db->prepare(
                        'SELECT r.registration_id,r.placement_rank,r.kills,r.bonus_input,r.penalty_input
                         FROM results r JOIN matches m ON m.id=r.match_id
                         WHERE r.tournament_id=? AND m.match_number=?'
                    );
                    $stmt->execute([$selectedTournament['id'], $activeMatchNumber]);
                    foreach ($stmt->fetchAll() as $result) {
                        $existingMatchResults[(int) $result['registration_id']] = $result;
                    }
                    $stmt = $db->prepare(
                        'SELECT note FROM matches WHERE tournament_id=? AND match_number=? LIMIT 1'
                    );
                    $stmt->execute([$selectedTournament['id'], $activeMatchNumber]);
                    $currentMatchNote = (string) ($stmt->fetchColumn() ?: '');
                }
                $contentFile = 'admin/results';
            } elseif ($page === 'admin-users') {
                $search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
                $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
                if (!in_array($statusFilter, ['all','active','suspended'], true)) $statusFilter = 'all';
                $where = 'WHERE u.role=\'user\'';
                $params = [];
                if ($statusFilter !== 'all') { $where .= ' AND u.status=?'; $params[] = $statusFilter; }
                if ($search !== '') { $where .= ' AND (u.email LIKE ? OR p.display_name LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
                $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
                [$users, $total, $pageCount, $pageNumber] = paged_rows(
                    'SELECT u.id,u.email,u.role,u.status,u.created_at,p.display_name,COALESCE(w.balance_minor,0) AS balance_minor
                     FROM users u LEFT JOIN profiles p ON p.user_id=u.id LEFT JOIN wallet_accounts w ON w.user_id=u.id ' . $where . ' ORDER BY u.created_at DESC',
                    'SELECT COUNT(*) FROM users u LEFT JOIN profiles p ON p.user_id=u.id ' . $where,
                    $params, $pageNumber, 20
                );
                $teams = $db->query(
                    'SELECT t.id,t.name,t.created_at,COALESCE(p.display_name,u.email) AS captain_name,COUNT(tm.user_id) AS member_count
                     FROM teams t JOIN users u ON u.id=t.captain_id LEFT JOIN profiles p ON p.user_id=u.id
                     LEFT JOIN team_members tm ON tm.team_id=t.id GROUP BY t.id,t.name,t.created_at,p.display_name,u.email
                     ORDER BY t.created_at DESC LIMIT 30'
                )->fetchAll();
                $teamCount = (int) $db->query('SELECT COUNT(*) FROM teams')->fetchColumn();
                $contentFile = 'admin/users';
            } elseif ($page === 'admin-teams') {
                $search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
                $where = 'WHERE 1=1';
                $params = [];
                if ($search !== '') {
                    $where .= ' AND (t.name LIKE ? OR u.email LIKE ? OR p.display_name LIKE ?)';
                    for ($i = 0; $i < 3; $i++) $params[] = '%' . $search . '%';
                }
                $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
                [$teams, $total, $pageCount, $pageNumber] = paged_rows(
                    'SELECT t.id,t.name,t.captain_id,t.created_at,u.email AS captain_email,p.display_name AS captain_name,
                            (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id=t.id) AS member_count,
                            (SELECT COUNT(*) FROM team_invitations ti WHERE ti.team_id=t.id AND ti.status=\'pending\' AND ti.expires_at>UTC_TIMESTAMP()) AS pending_invites,
                            (SELECT COUNT(*) FROM registrations r JOIN tournaments tr ON tr.id=r.tournament_id
                             WHERE r.team_id=t.id AND r.status=\'confirmed\' AND tr.status IN (\'open\',\'locked\',\'live\')) AS active_entries
                     FROM teams t JOIN users u ON u.id=t.captain_id LEFT JOIN profiles p ON p.user_id=u.id ' . $where . ' ORDER BY t.created_at DESC',
                    'SELECT COUNT(*) FROM teams t JOIN users u ON u.id=t.captain_id LEFT JOIN profiles p ON p.user_id=u.id ' . $where,
                    $params, $pageNumber, 20
                );
                foreach ($teams as &$team) {
                    $stmt = $db->prepare(
                        'SELECT tm.user_id,tm.member_role,tm.joined_at,u.email,u.status,p.display_name
                         FROM team_members tm JOIN users u ON u.id=tm.user_id LEFT JOIN profiles p ON p.user_id=u.id
                         WHERE tm.team_id=? ORDER BY tm.member_role DESC,tm.joined_at'
                    );
                    $stmt->execute([$team['id']]);
                    $team['members'] = $stmt->fetchAll();
                }
                unset($team);
                $contentFile = 'admin/teams';
            } elseif ($page === 'admin-wallet') {
                $depositSearch = mb_substr(trim(is_string($_GET['deposit_q'] ?? null) ? $_GET['deposit_q'] : ''), 0, 100);
                $depositStatus = is_string($_GET['deposit_status'] ?? null) ? $_GET['deposit_status'] : 'pending';
                if (!in_array($depositStatus, ['all','pending','approved','rejected'], true)) $depositStatus = 'pending';
                $depositWhere = 'WHERE 1=1';
                $depositParams = [];
                if ($depositStatus !== 'all') {
                    $depositWhere .= ' AND dr.status=?';
                    $depositParams[] = $depositStatus;
                }
                if ($depositSearch !== '') {
                    $depositWhere .= ' AND (u.email LIKE ? OR p.display_name LIKE ? OR dr.utr_number LIKE ?)';
                    for ($i = 0; $i < 3; $i++) $depositParams[] = '%' . $depositSearch . '%';
                }
                $depositPage = max(1, (int) ($_GET['deposit_p'] ?? 1));
                [$depositRequests, $depositTotal, $depositPageCount, $depositPage] = paged_rows(
                    'SELECT dr.*,u.email,p.display_name FROM deposit_requests dr
                     LEFT JOIN users u ON u.id=dr.user_id LEFT JOIN profiles p ON p.user_id=u.id ' .
                     $depositWhere . ' ORDER BY dr.created_at DESC,dr.id DESC',
                    'SELECT COUNT(*) FROM deposit_requests dr
                     LEFT JOIN users u ON u.id=dr.user_id LEFT JOIN profiles p ON p.user_id=u.id ' . $depositWhere,
                    $depositParams, $depositPage, 20
                );
                $manualDepositTotals = [
                    'pending' => (int) $db->query("SELECT COUNT(*) FROM deposit_requests WHERE status='pending'")->fetchColumn(),
                    'approved_minor' => (int) $db->query("SELECT COALESCE(SUM(amount_minor),0) FROM deposit_requests WHERE status='approved'")->fetchColumn(),
                ];

                $walletUserSearch = mb_substr(trim(is_string($_GET['wallet_user_q'] ?? null) ? $_GET['wallet_user_q'] : ''), 0, 100);
                $walletUsers = [];
                if ($walletUserSearch !== '') {
                    $term = '%' . $walletUserSearch . '%';
                    $stmt = $db->prepare(
                        "SELECT u.id,u.email,u.status,p.display_name,COALESCE(w.balance_minor,0) AS balance_minor,
                                COALESCE(w.reserved_minor,0) AS reserved_minor
                         FROM users u LEFT JOIN profiles p ON p.user_id=u.id
                         LEFT JOIN wallet_accounts w ON w.user_id=u.id
                         WHERE u.role='user' AND (u.email LIKE ? OR p.display_name LIKE ? OR CAST(u.id AS CHAR) LIKE ?)
                         ORDER BY u.email LIMIT 30"
                    );
                    $stmt->execute([$term, $term, $term]);
                    $walletUsers = $stmt->fetchAll();
                }
                if (!isset($_SESSION['_wallet_adjustment_nonce']) ||
                    !preg_match('/^[a-f0-9]{32}$/', (string) $_SESSION['_wallet_adjustment_nonce'])) {
                    $_SESSION['_wallet_adjustment_nonce'] = bin2hex(random_bytes(16));
                }
                $walletAdjustNonce = $_SESSION['_wallet_adjustment_nonce'];

                $ledgerSearch = mb_substr(trim(is_string($_GET['ledger_q'] ?? null) ? $_GET['ledger_q'] : ''), 0, 100);
                $ledgerType = is_string($_GET['ledger_type'] ?? null) ? $_GET['ledger_type'] : 'all';
                $ledgerTypes = ['deposit','entry_fee','entry_refund','prize','withdrawal_reserve','withdrawal_release','withdrawal_paid','admin_adjustment'];
                if ($ledgerType !== 'all' && !in_array($ledgerType, $ledgerTypes, true)) $ledgerType = 'all';
                $ledgerWhere = 'WHERE 1=1';
                $ledgerParams = [];
                if ($ledgerType !== 'all') { $ledgerWhere .= ' AND wt.transaction_type=?'; $ledgerParams[] = $ledgerType; }
                if ($ledgerSearch !== '') {
                    $ledgerWhere .= ' AND (u.email LIKE ? OR p.display_name LIKE ? OR wt.idempotency_key LIKE ? OR CAST(wt.reference_id AS CHAR) LIKE ?)';
                    for ($i = 0; $i < 4; $i++) $ledgerParams[] = '%' . $ledgerSearch . '%';
                }
                $ledgerPage = max(1, (int) ($_GET['ledger_p'] ?? 1));
                [$ledger, $ledgerTotal, $ledgerPages, $ledgerPage] = paged_rows(
                    'SELECT wt.*,u.email,p.display_name FROM wallet_transactions wt
                     JOIN users u ON u.id=wt.user_id LEFT JOIN profiles p ON p.user_id=u.id ' . $ledgerWhere . ' ORDER BY wt.id DESC',
                    'SELECT COUNT(*) FROM wallet_transactions wt JOIN users u ON u.id=wt.user_id
                     LEFT JOIN profiles p ON p.user_id=u.id ' . $ledgerWhere,
                    $ledgerParams, $ledgerPage, 25
                );
                $contentFile = 'admin/wallet';
            } elseif ($page === 'admin-withdrawals') {
                $search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
                $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
                if (!in_array($statusFilter, ['all','pending','approved','processing','paid','rejected'], true)) $statusFilter = 'all';
                $where = 'WHERE 1=1';
                $params = [];
                if ($statusFilter !== 'all') { $where .= ' AND wr.status=?'; $params[] = $statusFilter; }
                if ($search !== '') { $where .= ' AND (u.email LIKE ? OR p.display_name LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
                $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
                [$withdrawals, $total, $pageCount, $pageNumber] = paged_rows(
                    'SELECT wr.*,u.email,p.display_name FROM withdrawal_requests wr JOIN users u ON u.id=wr.user_id
                     LEFT JOIN profiles p ON p.user_id=u.id ' . $where . ' ORDER BY wr.created_at DESC',
                    'SELECT COUNT(*) FROM withdrawal_requests wr JOIN users u ON u.id=wr.user_id LEFT JOIN profiles p ON p.user_id=u.id ' . $where,
                    $params, $pageNumber, 20
                );
                foreach ($withdrawals as &$item) {
                    try { $item['upi_id'] = WithdrawalService::revealUpi($item); }
                    catch (Throwable) { $item['upi_id'] = 'Unable to decrypt'; }
                }
                unset($item);
                $contentFile = 'admin/withdrawals';
            } elseif ($page === 'admin-announcements') {
                $announcements = $db->query(
                    'SELECT a.*,u.email FROM announcements a JOIN users u ON u.id=a.created_by ORDER BY a.created_at DESC LIMIT 80'
                )->fetchAll();
                $contentFile = 'admin/announcements';
            } elseif ($page === 'admin-notifications') {
                $notificationSearch = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 100);
                $notificationUsers = [];
                $activeNotificationUsers = (int) $db->query(
                    "SELECT COUNT(*) FROM users WHERE role='user' AND status='active'"
                )->fetchColumn();
                if ($notificationSearch !== '') {
                    $term = '%' . $notificationSearch . '%';
                    $stmt = $db->prepare(
                        "SELECT u.id,u.email,p.display_name
                         FROM users u LEFT JOIN profiles p ON p.user_id=u.id
                         WHERE u.role='user' AND u.status='active'
                           AND (u.email LIKE ? OR p.display_name LIKE ? OR CAST(u.id AS CHAR) LIKE ?)
                         ORDER BY u.email LIMIT 200"
                    );
                    $stmt->execute([$term, $term, $term]);
                    $notificationUsers = $stmt->fetchAll();
                }
                $contentFile = 'admin/notifications';
            } elseif ($page === 'admin-support') {
                $search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
                $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
                if (!in_array($statusFilter, ['all','open','in_progress','resolved','closed'], true)) $statusFilter = 'all';
                $where = 'WHERE 1=1';
                $params = [];
                if ($statusFilter !== 'all') { $where .= ' AND s.status=?'; $params[] = $statusFilter; }
                if ($search !== '') { $where .= ' AND (s.contact_email LIKE ? OR s.subject LIKE ? OR s.message LIKE ?)'; for($i=0;$i<3;$i++) $params[] = '%' . $search . '%'; }
                $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
                [$requests, $total, $pageCount, $pageNumber] = paged_rows(
                    'SELECT s.* FROM support_requests s ' . $where . ' ORDER BY s.created_at DESC',
                    'SELECT COUNT(*) FROM support_requests s ' . $where, $params, $pageNumber, 20
                );
                $contentFile = 'admin/support';
            } elseif ($page === 'admin-audit') {
                $search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 80);
                $entityFilter = is_string($_GET['entity'] ?? null) ? $_GET['entity'] : 'all';
                $entityTypes = $db->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);
                if ($entityFilter !== 'all' && !in_array($entityFilter, $entityTypes, true)) $entityFilter = 'all';
                $where = 'WHERE 1=1';
                $params = [];
                if ($entityFilter !== 'all') { $where .= ' AND a.entity_type=?'; $params[] = $entityFilter; }
                if ($search !== '') { $where .= ' AND (a.action LIKE ? OR a.entity_type LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
                $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
                [$logs, $total, $pageCount, $pageNumber] = paged_rows(
                    'SELECT a.*,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ' . $where . ' ORDER BY a.created_at DESC',
                    'SELECT COUNT(*) FROM audit_logs a ' . $where, $params, $pageNumber, 25
                );
                $contentFile = 'admin/audit';
            } else {
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='min_withdrawal_minor'");
                $stmt->execute();
                $minWithdrawal = (int) ($stmt->fetchColumn() ?: 10000);
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key='max_withdrawal_minor'");
                $stmt->execute();
                $maxWithdrawal = (int) ($stmt->fetchColumn() ?: 5000000);
                $contentFile = 'admin/settings';
            }
            break;
        }
        default:
            return ['not_found' => true];
    }

    ob_start();
    require dirname(__DIR__) . '/views/pages/' . $contentFile . '.php';
    $content = (string) ob_get_clean();
    return compact('content', 'pageTitle', 'current') + ['not_found' => false];
}

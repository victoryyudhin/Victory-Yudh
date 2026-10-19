<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

date_default_timezone_set((string) config('timezone', 'Asia/Kolkata'));

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_name('victory_yudh_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header_remove('X-Powered-By');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
$cspNonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; script-src 'self' 'nonce-$cspNonce'; connect-src 'self'; frame-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
if ($https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

require_once __DIR__ . '/services/WalletService.php';
require_once __DIR__ . '/services/TournamentService.php';
require_once __DIR__ . '/services/ScoringService.php';
require_once __DIR__ . '/services/DepositService.php';
require_once __DIR__ . '/services/TeamService.php';
require_once __DIR__ . '/services/WithdrawalService.php';

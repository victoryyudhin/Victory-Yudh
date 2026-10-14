<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) config('app_url', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path), true, 303);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        render_error_page(419, 'This form expired. Refresh the page and try again.');
        exit;
    }
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

function current_user(bool $refresh = false): ?array
{
    static $user = null;
    static $loaded = false;
    if ($loaded && !$refresh) {
        return $user;
    }
    $loaded = true;
    $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        $user = null;
        return null;
    }
    $stmt = Database::connection()->prepare(
        'SELECT u.id, u.email, u.role, u.status, p.display_name, p.free_fire_uid
         FROM users u LEFT JOIN profiles p ON p.user_id=u.id WHERE u.id=? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'active') {
        unset($_SESSION['user_id']);
        $user = null;
        return null;
    }
    $user = $row;
    return $user;
}

function require_auth(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Sign in to continue.');
        redirect('index.php?page=login');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_auth();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        render_error_page(403, 'You do not have permission to view this page.');
        exit;
    }
    return $user;
}

function notify_user(int $userId, string $type, string $title, string $body, ?string $target = null): void
{
    $stmt = Database::connection()->prepare(
        'INSERT INTO notifications (user_id, notification_type, title, body, target_url) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([$userId, $type, mb_substr($title, 0, 120), mb_substr($body, 0, 500), $target]);
}

function audit_log(?int $actorId, string $action, string $entityType, ?int $entityId, ?array $before = null, ?array $after = null): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $packedIp = is_string($ip) ? @inet_pton($ip) : false;
    $stmt = Database::connection()->prepare(
        'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, before_data, after_data, ip_address)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $actorId,
        mb_substr($action, 0, 100),
        mb_substr($entityType, 0, 50),
        $entityId,
        $before === null ? null : json_encode(redact_audit_data($before), JSON_THROW_ON_ERROR),
        $after === null ? null : json_encode(redact_audit_data($after), JSON_THROW_ON_ERROR),
        $packedIp === false ? null : $packedIp,
    ]);
}

function redact_audit_data(array $data): array
{
    foreach (['room_password', 'password', 'password_hash', 'api_key', 'api_secret', 'webhook_secret'] as $key) {
        if (array_key_exists($key, $data)) {
            $data[$key] = '[REDACTED]';
        }
    }
    return $data;
}

function encrypt_private_value(string $value): array
{
    if ($value === '') {
        return [null, null, null];
    }
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', app_key(), true), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($encrypted)) {
        throw new RuntimeException('The private value could not be secured.');
    }
    return [$encrypted, $iv, $tag];
}

function decrypt_private_value(mixed $encrypted, mixed $iv, mixed $tag): string
{
    if (!is_string($encrypted) || !is_string($iv) || !is_string($tag)) {
        return '';
    }
    $value = openssl_decrypt($encrypted, 'aes-256-gcm', hash('sha256', app_key(), true), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($value)) {
        throw new RuntimeException('Private tournament information could not be decrypted.');
    }
    return $value;
}

function money_minor(string|int $input): int
{
    $value = trim((string) $input);
    if (!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?$/', $value, $matches)) {
        throw new InvalidArgumentException('Enter a valid amount in rupees (up to two decimal places).');
    }
    $rupees = (int) $matches[1];
    $paise = isset($matches[2]) ? (int) str_pad($matches[2], 2, '0') : 0;
    return $rupees * 100 + $paise;
}

function format_money(int|string $minor): string
{
    $amount = (int) $minor;
    $negative = $amount < 0;
    $amount = abs($amount);
    return ($negative ? '−' : '') . '₹' . number_format(intdiv($amount, 100)) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}

function post_string(string $key, int $maxLength = 255, bool $required = false): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid form value.');
    }
    $value = trim($value);
    if ($required && $value === '') {
        throw new InvalidArgumentException('Complete all required fields.');
    }
    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException('A form value is too long.');
    }
    return $value;
}

function post_int(string $key, int $min = 0, int $max = PHP_INT_MAX): int
{
    $value = $_POST[$key] ?? null;
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < $min || $number > $max) {
        throw new InvalidArgumentException('Enter a valid number.');
    }
    return $number;
}

function rate_limit(string $scope, int $maxAttempts, int $windowSeconds): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash_hmac('sha256', $scope . '|' . $ip, app_key());
    $window = intdiv(time(), $windowSeconds) * $windowSeconds;
    $db = Database::connection();
    $stmt = $db->prepare(
        'INSERT INTO rate_limits (scope_key, window_started_at, attempts) VALUES (?,FROM_UNIXTIME(?),1)
         ON DUPLICATE KEY UPDATE attempts=attempts+1'
    );
    $stmt->execute([$key, $window]);
    $db->prepare('DELETE FROM rate_limits WHERE scope_key=? AND window_started_at<FROM_UNIXTIME(?)')
        ->execute([$key, $window - $windowSeconds]);
    $stmt = $db->prepare('SELECT attempts FROM rate_limits WHERE scope_key=? AND window_started_at=FROM_UNIXTIME(?)');
    $stmt->execute([$key, $window]);
    return (int) $stmt->fetchColumn() <= $maxAttempts;
}

function render_error_page(int $code, string $message): void
{
    http_response_code($code);
    $pageTitle = 'Request issue';
    $content = '<main class="mx-auto max-w-xl px-5 py-24 text-center"><p class="text-violet-300 text-sm font-bold">VICTORY YUDH · ' . h($code) . '</p><h1 class="mt-4 text-3xl font-black">' . h($message) . '</h1><a class="btn-primary mt-8 inline-flex rounded-xl px-5 py-3 font-bold" href="' . h(app_url('index.php')) . '">Back to home</a></main>';
    require dirname(__DIR__) . '/views/layout.php';
}

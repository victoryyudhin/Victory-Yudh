<?php
declare(strict_types=1);

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1 || getenv($key) !== false) {
            continue;
        }
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function config(string $key, mixed $default = null): mixed
{
    $keys = [
        'app_env' => 'APP_ENV',
        'app_url' => 'APP_URL',
        'app_key' => 'APP_KEY',
        'timezone' => 'APP_TIMEZONE',
        'db_host' => 'DB_HOST',
        'db_port' => 'DB_PORT',
        'db_name' => 'DB_NAME',
        'db_user' => 'DB_USER',
        'db_password' => 'DB_PASSWORD',
        'db_charset' => 'DB_CHARSET',
        'razorpay_key_id' => 'RAZORPAY_KEY_ID',
        'razorpay_key_secret' => 'RAZORPAY_KEY_SECRET',
        'razorpay_webhook_secret' => 'RAZORPAY_WEBHOOK_SECRET',
        'upload_dir' => 'UPLOAD_DIR',
    ];
    $envName = $keys[$key] ?? null;
    if ($envName === null) {
        return $default;
    }
    $value = getenv($envName);
    return $value === false || $value === '' ? $default : $value;
}

function app_key(): string
{
    $key = (string) (config('app_key') ?: getenv('SESSION_SECRET') ?: '');
    if (strlen($key) < 32 || preg_match('/^(replace-with|change-me|example|changeme)/i', $key) === 1) {
        if (config('app_env', 'production') === 'production') {
            throw new RuntimeException('App security key is missing. Configure APP_KEY or SESSION_SECRET.');
        }
        $key = hash('sha256', __FILE__ . php_uname() . (string) ($_SERVER['HTTP_HOST'] ?? 'local-dev'));
    }
    return $key;
}

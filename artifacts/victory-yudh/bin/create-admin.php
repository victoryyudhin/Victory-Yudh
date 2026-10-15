<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/db.php';
require dirname(__DIR__) . '/app/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this setup tool from the command line only.\n");
    exit(1);
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    return trim((string) fgets(STDIN));
}

function prompt_secret(string $label): string
{
    fwrite(STDOUT, $label);
    if (function_exists('shell_exec') && DIRECTORY_SEPARATOR === '/') {
        @shell_exec('stty -echo');
        $value = trim((string) fgets(STDIN));
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $value;
    }
    return trim((string) fgets(STDIN));
}

try {
    $db = Database::connection();
    $count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    if ($count > 0) {
        fwrite(STDERR, "An administrator already exists. This one-time setup command is disabled.\n");
        exit(1);
    }

    $email = mb_strtolower(prompt('Admin email: '));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    $displayName = prompt('Admin display name: ');
    if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 80) {
        throw new InvalidArgumentException('Display name must be 2 to 80 characters.');
    }
    $password = prompt_secret('Create a password (at least 16 characters): ');
    $confirm = prompt_secret('Confirm password: ');
    if (strlen($password) < 16 || !hash_equals($password, $confirm)) {
        throw new InvalidArgumentException('Passwords must match and be at least 16 characters.');
    }

    if ((int) $db->query("SELECT GET_LOCK('victory_yudh_initial_admin_setup', 10)")->fetchColumn() !== 1) {
        throw new RuntimeException('Could not safely acquire the one-time admin setup lock.');
    }
    try {
        $db->beginTransaction();
        $check = $db->query("SELECT COUNT(*) FROM users WHERE role='admin'");
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('An administrator was created concurrently. No account was changed.');
        }
        $stmt = $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?,?, 'admin')");
        $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO profiles (user_id, display_name) VALUES (?,?)')->execute([$userId, $displayName]);
        $db->prepare('INSERT INTO wallet_accounts (user_id) VALUES (?)')->execute([$userId]);
        audit_log($userId, 'admin.initial_setup', 'user', $userId, null, ['role' => 'admin']);
        $db->commit();
        fwrite(STDOUT, "Administrator account created. Sign in using the email you entered.\n");
    } finally {
        $db->query("SELECT RELEASE_LOCK('victory_yudh_initial_admin_setup')");
    }
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Admin setup failed: ' . get_class($e) . PHP_EOL);
    exit(1);
}

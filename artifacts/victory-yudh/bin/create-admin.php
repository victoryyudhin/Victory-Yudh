<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/db.php';
require dirname(__DIR__) . '/app/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this setup tool from the command line only.\n");
    exit(1);
}

$arguments = array_slice($argv, 1);
$nonInteractive = in_array('--non-interactive', $arguments, true);
$promoteExisting = in_array('--promote-existing', $arguments, true);
$validArguments = $arguments === []
    || $arguments === ['--non-interactive']
    || $arguments === ['--non-interactive', '--promote-existing']
    || $arguments === ['--promote-existing', '--non-interactive'];
if (!$validArguments) {
    fwrite(STDERR, "Usage: php bin/create-admin.php [--non-interactive [--promote-existing]]\n");
    exit(2);
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

function required_bootstrap_value(string $name): string
{
    $value = getenv($name);
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);

    if ($value === false || $value === '') {
        throw new InvalidArgumentException("Set $name to use --non-interactive.");
    }
    return $value;
}

try {
    $db = Database::connection();
    if (!$promoteExisting) {
        $count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($count > 0) {
            fwrite(STDERR, "An administrator already exists. This one-time setup command is disabled.\n");
            exit(1);
        }
    }

    if ($nonInteractive) {
        $email = required_bootstrap_value('INITIAL_ADMIN_EMAIL');
        $displayName = required_bootstrap_value('INITIAL_ADMIN_NAME');
        $password = required_bootstrap_value('INITIAL_ADMIN_PASSWORD');
        $confirm = null;
    } else {
        $email = prompt('Admin email: ');
        $displayName = prompt('Admin display name: ');
        $password = prompt_secret('Create a password (at least 16 characters): ');
        $confirm = prompt_secret('Confirm password: ');
    }

    $email = mb_strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    $displayName = trim($displayName);
    if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 80) {
        throw new InvalidArgumentException('Display name must be 2 to 80 characters.');
    }
    if (strlen($password) < 16 || ($confirm !== null && !hash_equals($password, $confirm))) {
        throw new InvalidArgumentException('Passwords must match and be at least 16 characters.');
    }

    $lockName = 'victory_yudh_initial_admin_setup';
    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not safely acquire the one-time admin setup lock.');
    }
    try {
        $db->beginTransaction();
        if ($promoteExisting) {
            $find = $db->prepare('SELECT id, role, status FROM users WHERE email=? FOR UPDATE');
            $find->execute([$email]);
            $account = $find->fetch();
            if (!$account) {
                throw new RuntimeException('No existing account matches the configured email.');
            }
            if ($account['role'] !== 'user') {
                throw new RuntimeException('The existing account is not eligible for promotion.');
            }
            if ($account['status'] !== 'active') {
                throw new RuntimeException('A suspended account cannot be promoted.');
            }

            $userId = (int) $account['id'];
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            unset($password);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Could not secure the admin password.');
            }
            $update = $db->prepare("UPDATE users SET role='admin', password_hash=? WHERE id=? AND role='user'");
            $update->execute([$passwordHash, $userId]);
            unset($passwordHash);

            $profile = $db->prepare('SELECT user_id FROM profiles WHERE user_id=? FOR UPDATE');
            $profile->execute([$userId]);
            if ($profile->fetchColumn() === false) {
                $db->prepare('INSERT INTO profiles (user_id, display_name) VALUES (?,?)')
                    ->execute([$userId, $displayName]);
            } else {
                $db->prepare('UPDATE profiles SET display_name=? WHERE user_id=?')
                    ->execute([$displayName, $userId]);
            }
            audit_log(null, 'admin.account_promoted', 'user', $userId, ['role' => 'user'], ['role' => 'admin']);
            $db->commit();
            fwrite(STDOUT, "Existing account promoted to administrator. Sign in using the configured email.\n");
        } else {
            $check = $db->query("SELECT COUNT(*) FROM users WHERE role='admin'");
            if ((int) $check->fetchColumn() > 0) {
                throw new RuntimeException('An administrator was created concurrently. No account was changed.');
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            unset($password);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Could not secure the admin password.');
            }
            $stmt = $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?,?, 'admin')");
            $stmt->execute([$email, $passwordHash]);
            unset($passwordHash);
            $userId = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO profiles (user_id, display_name) VALUES (?,?)')->execute([$userId, $displayName]);
            $db->prepare('INSERT INTO wallet_accounts (user_id) VALUES (?)')->execute([$userId]);
            audit_log($userId, 'admin.initial_setup', 'user', $userId, null, ['role' => 'admin']);
            $db->commit();
            fwrite(STDOUT, "Administrator account created. Sign in using the configured email.\n");
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Admin setup failed: ' . get_class($e) . PHP_EOL);
    exit(1);
}

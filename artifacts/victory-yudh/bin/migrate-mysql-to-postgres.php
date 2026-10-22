<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/config.php';
require dirname(__DIR__) . '/app/db.php';

$tables = [
    'schema_migrations',
    'users',
    'profiles',
    'teams',
    'team_members',
    'team_invitations',
    'tournaments',
    'scoring_rules',
    'registrations',
    'registration_members',
    'matches',
    'results',
    'wallet_accounts',
    'wallet_transactions',
    'withdrawal_requests',
    'notifications',
    'announcements',
    'support_requests',
    'settings',
    'audit_logs',
    'rate_limits',
    'deposit_requests',
];
$binaryColumns = [
    'room_id_enc', 'room_id_iv', 'room_id_tag',
    'room_password_enc', 'room_password_iv', 'room_password_tag',
    'payout_details_enc', 'payout_details_iv', 'payout_details_tag', 'ip_address',
];

function rekey_gcm_value(?string $ciphertext, ?string $iv, ?string $tag, string $oldKey, string $newKey): array
{
    if ($ciphertext === null) {
        return [null, null, null];
    }
    if ($iv === null || $tag === null) {
        throw new RuntimeException('Encrypted source record is incomplete.');
    }
    $plain = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        hash('sha256', $oldKey, true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($plain === false) {
        throw new RuntimeException('An encrypted source record could not be verified.');
    }
    $newIv = random_bytes(12);
    $newTag = '';
    $newCiphertext = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        hash('sha256', $newKey, true),
        OPENSSL_RAW_DATA,
        $newIv,
        $newTag
    );
    if ($newCiphertext === false || strlen($newTag) !== 16) {
        throw new RuntimeException('An encrypted source record could not be migrated.');
    }
    $verified = openssl_decrypt(
        $newCiphertext,
        'aes-256-gcm',
        hash('sha256', $newKey, true),
        OPENSSL_RAW_DATA,
        $newIv,
        $newTag
    );
    if ($verified === false || !hash_equals($plain, $verified)) {
        throw new RuntimeException('An encrypted source record failed migration verification.');
    }
    return [$newCiphertext, $newIv, $newTag];
}

function source_row_count(PDO $db, string $table): int
{
    $stmt = $db->query('SELECT COUNT(*) FROM `' . $table . '`');
    return (int) $stmt->fetchColumn();
}

try {
    if (Database::driver() !== 'pgsql') {
        throw new RuntimeException('Set DB_DRIVER=pgsql and connect to the Replit development database first.');
    }
    $socket = (string) (getenv('DB_SOURCE_SOCKET') ?: dirname(__DIR__) . '/var/run/mysql.sock');
    $sourceName = (string) (getenv('DB_SOURCE_NAME') ?: 'victory_yudh');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $sourceName) || !is_readable($socket)) {
        throw new RuntimeException('The local MariaDB source database is unavailable.');
    }
    $source = new PDO(
        'mysql:unix_socket=' . $socket . ';dbname=' . $sourceName . ';charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $target = Database::connection();
    if ((string) $target->query('SELECT current_schema()')->fetchColumn() !== 'public') {
        throw new RuntimeException('Data transfer must target the public schema.');
    }
    $oldKeyPath = dirname(__DIR__) . '/var/dev-app-key';
    $oldKey = is_readable($oldKeyPath) ? trim((string) file_get_contents($oldKeyPath)) : '';
    $newKey = (string) (getenv('APP_KEY') ?: getenv('SESSION_SECRET') ?: '');
    if (strlen($oldKey) < 32 || strlen($newKey) < 32) {
        throw new RuntimeException('Both the source encryption key and a stable Replit app key are required.');
    }

    $allowedSeedTables = ['schema_migrations', 'settings'];
    foreach ($tables as $table) {
        $targetCount = (int) $target->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        if ($targetCount > 0 && !in_array($table, $allowedSeedTables, true)) {
            throw new RuntimeException('The PostgreSQL target is not empty; refusing to merge source records.');
        }
    }

    $target->beginTransaction();
    $counts = [];
    foreach ($tables as $table) {
        $rows = $source->query('SELECT * FROM `' . $table . '`');
        $insert = null;
        $count = 0;
        while ($row = $rows->fetch()) {
            if ($table === 'tournaments') {
                foreach ([
                    ['room_id_enc', 'room_id_iv', 'room_id_tag'],
                    ['room_password_enc', 'room_password_iv', 'room_password_tag'],
                ] as [$cipher, $iv, $tag]) {
                    [$row[$cipher], $row[$iv], $row[$tag]] = rekey_gcm_value(
                        $row[$cipher] === null ? null : (string) $row[$cipher],
                        $row[$iv] === null ? null : (string) $row[$iv],
                        $row[$tag] === null ? null : (string) $row[$tag],
                        $oldKey,
                        $newKey
                    );
                }
            } elseif ($table === 'withdrawal_requests') {
                [
                    $row['payout_details_enc'],
                    $row['payout_details_iv'],
                    $row['payout_details_tag'],
                ] = rekey_gcm_value(
                    (string) $row['payout_details_enc'],
                    (string) $row['payout_details_iv'],
                    (string) $row['payout_details_tag'],
                    $oldKey,
                    $newKey
                );
            }

            $columns = array_keys($row);
            foreach ($columns as $column) {
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $column)) {
                    throw new RuntimeException('A source table contains an unsupported column name.');
                }
            }
            if ($insert === null) {
                $quotedColumns = array_map(static fn(string $column): string => '"' . $column . '"', $columns);
                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $conflict = match ($table) {
                    'settings' => 'ON CONFLICT (setting_key) DO UPDATE SET
                        setting_value=EXCLUDED.setting_value, updated_by=EXCLUDED.updated_by, updated_at=EXCLUDED.updated_at',
                    'schema_migrations' => 'ON CONFLICT (version) DO UPDATE SET applied_at=EXCLUDED.applied_at',
                    default => 'ON CONFLICT DO NOTHING',
                };
                $insert = $target->prepare(
                    'INSERT INTO "' . $table . '" (' . implode(',', $quotedColumns) . ') VALUES (' . $placeholders . ')
                     ' . $conflict
                );
            }
            foreach (array_values($row) as $index => $value) {
                $column = $columns[$index];
                if ($value !== null && in_array($column, $binaryColumns, true)) {
                    $value = '\\x' . bin2hex((string) $value);
                }
                $type = $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR;
                $insert->bindValue($index + 1, $value, $type);
            }
            $insert->execute();
            $count++;
        }
        $counts[$table] = $count;
    }

    foreach ($tables as $table) {
        $sourceCount = source_row_count($source, $table);
        $targetCount = (int) $target->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
        if ($sourceCount !== $targetCount) {
            throw new RuntimeException('Row-count verification failed for table ' . $table . '.');
        }
    }
    $walletMismatch = (int) $target->query(
        'SELECT COUNT(*) FROM wallet_accounts wa
         WHERE wa.balance_minor <> COALESCE(
           (SELECT SUM(wt.available_delta_minor) FROM wallet_transactions wt WHERE wt.user_id=wa.user_id), 0
         )
         OR wa.reserved_minor <> COALESCE(
           (SELECT SUM(wt.reserved_delta_minor) FROM wallet_transactions wt WHERE wt.user_id=wa.user_id), 0
         )'
    )->fetchColumn();
    if ($walletMismatch !== 0) {
        throw new RuntimeException('Wallet ledger verification failed after data transfer.');
    }

    foreach (['users','teams','team_invitations','tournaments','scoring_rules','registrations','matches',
        'results','wallet_transactions','withdrawal_requests','notifications','announcements','support_requests',
        'audit_logs','rate_limits','deposit_requests'] as $table) {
        $target->exec(
            "SELECT setval(pg_get_serial_sequence('public.$table', 'id'), " .
            "COALESCE((SELECT MAX(id) FROM \"$table\"), 1), " .
            "EXISTS(SELECT 1 FROM \"$table\"))"
        );
    }
    $target->commit();
    foreach ($counts as $table => $count) {
        echo $table . ': ' . $count . " rows copied\n";
    }
    echo "Migration complete. MariaDB source was left unchanged.\n";
} catch (Throwable $error) {
    if (isset($target) && $target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}

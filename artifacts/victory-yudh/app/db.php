<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        $driver = strtolower((string) config(
            'db_driver',
            getenv('DATABASE_URL') !== false || getenv('PGHOST') !== false ? 'pgsql' : 'mysql'
        ));
        if ($driver === 'pgsql') {
            $url = (string) config('database_url', '');
            $host = (string) config('pg_host', '');
            $port = (string) config('pg_port', '5432');
            $name = (string) config('pg_database', '');
            $user = (string) config('pg_user', '');
            $password = (string) config('pg_password', '');
            $sslmode = (string) config('pg_sslmode', '');
            if ($url !== '') {
                $parts = parse_url($url);
                if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
                    throw new RuntimeException('PostgreSQL connection URL is invalid.');
                }
                $host = (string) $parts['host'];
                $port = (string) ($parts['port'] ?? 5432);
                $name = rawurldecode(ltrim((string) $parts['path'], '/'));
                $user = rawurldecode((string) ($parts['user'] ?? ''));
                $password = rawurldecode((string) ($parts['pass'] ?? ''));
                parse_str((string) ($parts['query'] ?? ''), $query);
                $sslmode = (string) ($query['sslmode'] ?? $sslmode);
            }
            if ($host === '' || $name === '' || $user === '') {
                throw new RuntimeException('PostgreSQL configuration is incomplete.');
            }
            $dsn = "pgsql:host=$host;port=$port;dbname=$name";
            if ($sslmode !== '') {
                if (!in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
                    throw new RuntimeException('Invalid PostgreSQL SSL mode.');
                }
                $dsn .= ";sslmode=$sslmode";
            }
            self::$connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $schema = (string) config('db_schema', '');
            if ($schema !== '') {
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
                    throw new RuntimeException('Invalid PostgreSQL schema configuration.');
                }
                self::$connection->exec('SET search_path TO "' . $schema . '", public');
            }
            self::$connection->exec("SET TIME ZONE 'UTC'");
            return self::$connection;
        }
        if ($driver !== 'mysql') {
            throw new RuntimeException('Unsupported database driver.');
        }
        $host = (string) config('db_host', '127.0.0.1');
        $port = (string) config('db_port', '3306');
        $name = (string) config('db_name', '');
        $user = (string) config('db_user', '');
        $password = (string) config('db_password', '');
        $charset = (string) config('db_charset', 'utf8mb4');
        if ($name === '' || $user === '') {
            throw new RuntimeException('Database configuration is incomplete. Set DB_NAME and DB_USER.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name) || !preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
            throw new RuntimeException('Invalid database configuration.');
        }
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";
        self::$connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset",
        ]);
        self::$connection->exec("SET time_zone = '+00:00'");
        return self::$connection;
    }
}

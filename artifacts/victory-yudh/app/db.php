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

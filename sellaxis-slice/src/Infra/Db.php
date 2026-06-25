<?php

declare(strict_types=1);

namespace Forgeline\Infra;

use PDO;

final class Db
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '5432';
            $name = getenv('DB_NAME') ?: 'forgeline';
            $user = getenv('DB_USER') ?: 'forgeline';
            $pass = getenv('DB_PASSWORD') ?: 'forgeline';

            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$instance;
    }

    /** Allow tests to force a fresh connection against a different DSN. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}

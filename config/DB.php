<?php
/** One validated PDO connection per request, shared by every application query. */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/database-security.php';

final class DB
{
    private static ?PDO $connection = null;

    private function __construct() {}
    private function __clone() {}

    public static function getConnection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $connection = database_connect(DB_SETTINGS);
        $connection->exec("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");

        // Never cache a connection until validation and initialization succeed.
        return self::$connection = $connection;
    }
}

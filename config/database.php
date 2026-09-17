<?php
/**
 * CoopVote — PDO connection (singleton)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        /*
         * Put MySQL on the same clock as PHP.
         *
         * MySQL defaults to time_zone = SYSTEM, meaning the computer's own
         * zone, while config.php pins PHP to Asia/Manila. On a PC whose
         * Windows clock is set to anything else the two disagree by whole
         * hours, and every timestamp written by the database then reads
         * wrong to PHP. Registration codes were the visible casualty: a
         * brand-new code came out already expired, and resending produced
         * another expired one, so nobody could finish signing up.
         *
         * A numeric offset is used rather than a zone name because the
         * named-zone tables are not loaded in a default XAMPP.
         */
        $pdo->exec("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");
    } catch (PDOException $e) {
        /*
         * These three go wrong in different ways and need different fixes,
         * but they all used to read "Is MySQL running?". On a machine where
         * MySQL *was* running but the database had never been imported, that
         * sent people looking in the wrong place with no way forward.
         */
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        [$message, $fix] = match (true) {
            !extension_loaded('pdo_mysql') => [
                'The PHP MySQL driver is not enabled on this computer.',
                'Enable pdo_mysql in php.ini and restart Apache. Open setup.php to check the PHP extensions.',
            ],
            $code === 1049 => [
                'The "' . DB_NAME . '" database has not been created on this computer yet.',
                'Open http://localhost/phpmyadmin, click Import, and choose '
                    . 'database/coopvote_full.sql from this project folder.',
            ],
            $code === 1045 => [
                'MySQL refused the username and password in config/config.php.',
                'On a default XAMPP the user is "root" with an empty password. '
                    . 'Set DB_USER and DB_PASS in config/config.php to match this computer.',
            ],
            $code === 2002 || $code === 0 => [
                'Could not reach MySQL on ' . DB_HOST . ':' . DB_PORT . '.',
                'Open the XAMPP Control Panel and press Start next to MySQL. '
                    . 'If it is already running on a different port, set DB_PORT '
                    . 'in config/config.php to match.',
            ],
            default => [
                'The database could not be opened.',
                'Check that MySQL is running in XAMPP and that '
                    . 'database/coopvote_full.sql has been imported.',
            ],
        };

        http_response_code(500);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok'     => false,
            'error'  => $message,
            'fix'    => $fix,
            'code'   => 'DB_UNAVAILABLE',
            'detail' => DEBUG ? $e->getMessage() : null,
        ]);
        exit;
    }

    return $pdo;
}

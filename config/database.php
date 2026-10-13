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
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $security = is_file(__DIR__ . '/security-local.php') ? require __DIR__ . '/security-local.php' : [];
        $ca = getenv('COOPVOTE_DB_SSL_CA') ?: ($security['db_ssl_ca'] ?? '');
        $fixtureServer = PHP_SAPI === 'cli-server' && DB_NAME === 'coopvote_test';
        if (!$ca && PHP_SAPI !== 'cli' && !$fixtureServer) throw new RuntimeException('DB_TLS_REQUIRED');
        if ($ca) {
            if (!is_readable($ca)) throw new RuntimeException('Database TLS CA file is missing or unreadable.');
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        if ($ca) {
            $tls = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_version'")->fetch();
            if (!in_array($tls['Value'] ?? '', ['TLSv1.2', 'TLSv1.3'], true)) {
                throw new RuntimeException('Database connection must use verified TLS 1.2 or newer.');
            }
        }
        // Production web requests fail closed if a copied database lost encryption.
        // The isolated CLI test server deliberately rebuilds disposable fixtures.
        if (PHP_SAPI !== 'cli' && !$fixtureServer) {
            $encryption = $connection->prepare("SELECT COUNT(*) FROM information_schema.TABLES t
                LEFT JOIN information_schema.INNODB_TABLESPACES_ENCRYPTION e
                    ON e.NAME = CONCAT(t.TABLE_SCHEMA, '/', t.TABLE_NAME)
                WHERE t.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE'
                    AND (t.ENGINE <> 'InnoDB' OR REPLACE(UPPER(t.CREATE_OPTIONS),'`','') NOT LIKE '%ENCRYPTED=YES%'
                        OR COALESCE(e.ENCRYPTION_SCHEME,0) <> 1
                        OR COALESCE(e.CURRENT_KEY_VERSION,0) < 1)");
            $encryption->execute([DB_NAME]);
            if ((int)$encryption->fetchColumn() !== 0) {
                throw new RuntimeException('DB_ENCRYPTION_REQUIRED');
            }
        }

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
        $connection->exec("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");
        // Cache only a connection that passed every security check.
        $pdo = $connection;
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

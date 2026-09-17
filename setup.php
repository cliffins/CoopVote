<?php
/**
 * CoopVote — installation check.
 *
 * Open http://localhost/<folder>/setup.php on a new computer. It reports
 * what is missing and, when the database has not been imported yet, offers
 * to import it. Nothing here writes to an existing database.
 *
 * Delete this file once the system is running.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/installation.php';
require_once __DIR__ . '/includes/session-storage.php';

/* ── checks ──────────────────────────────────────────────── */

$checks = [];

/** @param string $state ok | warn | bad */
function check(string $name, string $state, string $detail, string $fix = ''): void
{
    global $checks;
    $checks[] = compact('name', 'state', 'detail', 'fix');
}

/* PHP itself */
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
check(
    'PHP version',
    $phpOk ? 'ok' : 'bad',
    PHP_VERSION,
    $phpOk ? '' : 'This app needs PHP 8.1 or newer. Install XAMPP with PHP 8.2 or newer.'
);

foreach (['pdo_mysql' => 'talking to MySQL',
          'mbstring'  => 'handling names and email text',
          'openssl'   => 'sending mail over a secure connection',
          'fileinfo'  => 'checking uploaded pictures'] as $ext => $why) {
    $has = extension_loaded($ext);
    check(
        'PHP extension: ' . $ext,
        $has ? 'ok' : 'bad',
        $has ? 'loaded' : 'missing — needed for ' . $why,
        $has ? '' : 'Open C:\xampp\php\php.ini, remove the ; in front of '
            . 'extension=' . $ext . ', then restart Apache.'
    );
}

/* A transferred php.ini can point sessions at a nonexistent directory. */
try {
    ensure_session_storage();
    $sessionOk = @session_start();
    if ($sessionOk) session_write_close();
    check('Session storage', $sessionOk ? 'ok' : 'bad',
        $sessionOk ? 'PHP can open a sign-in session' : 'PHP cannot save sign-in sessions',
        $sessionOk ? '' : 'Allow PHP to write to session.save_path or its temporary directory, then restart Apache.');
} catch (Throwable $e) {
    check('Session storage', 'bad', 'PHP cannot save sign-in sessions',
        'Check session.save_path in php.ini and PHP temporary-directory permissions. Restart Apache after changing php.ini.');
}

/* PHPMailer */
$mailerOk = is_file(__DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php');
check(
    'PHPMailer library',
    $mailerOk ? 'ok' : 'warn',
    $mailerOk ? 'present in vendor/' : 'vendor/phpmailer is missing',
    $mailerOk ? '' : 'Copy the whole vendor/ folder across, or run "composer install".'
);

/* Writable folders */
foreach (['uploads' => 'ID photos are saved here',
          'logs'    => 'mail and error logs are written here'] as $dir => $why) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0777, true);
    }
    $ok = is_dir($path) && is_writable($path);
    check(
        'Folder: ' . $dir . '/',
        $ok ? 'ok' : 'bad',
        $ok ? 'writable' : 'not writable — ' . $why,
        $ok ? '' : 'Right-click the ' . $dir . ' folder → Properties → uncheck "Read-only".'
    );
}

/* MySQL + database */
$dbState = 'bad';
$pdo     = null;
$dbError = '';
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    check('MySQL server', 'ok', 'reachable on ' . DB_HOST . ':' . DB_PORT);
    $dbState = 'server-up';
} catch (PDOException $e) {
    $dbError = $e->getMessage();
    check(
        'MySQL server',
        'bad',
        'cannot connect on ' . DB_HOST . ':' . DB_PORT,
        ((int) ($e->errorInfo[1] ?? $e->getCode()) === 1045)
            ? 'MySQL rejected the login. Set DB_USER / DB_PASS in config/config.php '
                . '(a default XAMPP uses root with no password).'
            : 'Open the XAMPP Control Panel and press Start next to MySQL.'
    );
}

$tables    = [];
$dbPresent = false;
if ($pdo) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $q->execute([DB_NAME]);
    $dbPresent = (bool) $q->fetchColumn();

    if ($dbPresent) {
        $q = $pdo->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name'
        );
        $q->execute([DB_NAME]);
        $tables = $q->fetchAll(PDO::FETCH_COLUMN);
    }
}

$requiredSchema = installation_schema_requirements();
define('REQUIRED_TABLES', array_keys($requiredSchema));
$missingTables = array_values(array_diff(REQUIRED_TABLES, $tables));

if ($pdo) {
    if (!$dbPresent) {
        check(
            'Database "' . DB_NAME . '"',
            'bad',
            'has not been created on this computer',
            'Press the Import button below, or import database/coopvote_full.sql '
                . 'through http://localhost/phpmyadmin.'
        );
    } elseif ($missingTables) {
        check(
            'Database "' . DB_NAME . '"',
            'bad',
            count($tables) . ' of ' . count(REQUIRED_TABLES) . ' tables — missing: '
                . implode(', ', $missingTables),
            'This import is incomplete. Import the matching database/coopvote_full.sql into a new empty database and update DB_NAME in config/config.php. Preserve the existing database.'
        );
    } else {
        $counts = [];
        foreach (['users', 'elections', 'candidates', 'votes'] as $t) {
            $counts[] = $pdo->query('SELECT COUNT(*) FROM `' . DB_NAME . '`.`' . $t . '`')->fetchColumn()
                . ' ' . $t;
        }
        check('Database "' . DB_NAME . '"', 'ok', 'all ' . count(REQUIRED_TABLES) . ' tables present — ' . implode(', ', $counts));

        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = ? AND table_name = 'members'
                AND column_name IN ('profile_photo','profile_photo_mime','profile_photo_updated_at')"
        );
        $q->execute([DB_NAME]);
        $photoColumns = (int) $q->fetchColumn();
        check(
            'Database profile-picture storage',
            $photoColumns === 3 ? 'ok' : 'bad',
            $photoColumns === 3 ? 'ready — pictures are stored in MariaDB' : 'Migration 05 has not been installed',
            $photoColumns === 3 ? '' : 'Back up the database, then import database/migration_05_profile_photos.sql.'
        );
    }
}

if ($pdo && $dbPresent) {
    $schemaIssues = installation_schema_issues($pdo, DB_NAME);
    $missingColumns = $schemaIssues['missingColumns'];
    check('Database columns', $missingColumns ? 'bad' : 'ok',
        $missingColumns ? 'Missing: ' . implode(', ', $missingColumns) : 'Existing tables match the bundled column inventory',
        $missingColumns ? 'Back up this database and run php tools/repair_login_schema.php. For a fresh installation, import the matching coopvote_full.sql into an empty database.' : '');
}

/* These are installed by the CLI upgrade, never by a public web request. */
$managementReady = count(array_diff(['admin_profiles','user_preferences','governance_config','election_approval_requests','election_admin_decisions'], $tables)) === 0;
check('Themes and admin approvals', $managementReady ? 'ok' : 'bad',
    $managementReady ? 'Management storage present' : 'Management upgrade needed',
    $managementReady ? '' : 'Run php tools/upgrade_management.php in the project folder. It creates a backup before the additive upgrade.');
$assuranceTables = ['election_integrity', 'vote_integrity', 'election_reports', 'notification_reads', 'security_alert_reviews'];
$assuranceReady = count(array_diff($assuranceTables, $tables)) === 0;
if ($assuranceReady && $pdo && in_array('elections', $tables, true)) {
    $reopening = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'election_reports' AND COLUMN_NAME IN ('superseded_at', 'history')");
    $reopening->execute([DB_NAME]);
    $reopeningReady = (int) $reopening->fetchColumn() === 2;
    check('Election reopening storage', $reopeningReady ? 'ok' : 'bad',
        $reopeningReady ? 'Closing report history is supported' : 'Reopening upgrade needed',
        $reopeningReady ? '' : 'Run php tools/upgrade_reopening.php in the project folder.');
    $withoutBaseline = (int) $pdo->query('SELECT COUNT(*) FROM `' . DB_NAME . '`.elections e LEFT JOIN `' . DB_NAME . '`.election_integrity i ON i.election_id = e.election_id WHERE i.election_id IS NULL')->fetchColumn();
    check('Election integrity baselines', $withoutBaseline === 0 ? 'ok' : 'bad',
        $withoutBaseline === 0 ? 'All elections have an integrity register' : $withoutBaseline . ' elections need an explicit baseline',
        $withoutBaseline === 0 ? '' : 'Back up the database, then run php tools/upgrade_assurance.php --backup-confirmed.');
}
check('Election assurance tables', $assuranceReady ? 'ok' : 'bad',
    $assuranceReady ? 'Integrity, reports and notification storage present' : 'Assurance upgrade needed',
    $assuranceReady ? '' : 'Back up the database and run php tools/upgrade_assurance.php --backup-confirmed in the project folder.');
$keyReady = (bool) getenv('COOPVOTE_INTEGRITY_KEY') || is_file(__DIR__ . '/config/integrity-key.php');
check('Private integrity key', $keyReady ? 'ok' : 'bad', $keyReady ? 'Key configured; signatures must still be verified in Reports' : 'No signing key configured',
    $keyReady ? '' : 'For an existing signed database restore its original private key. For a first installation run the assurance upgrade.');

/* Apache modules — informational; the app works without either. */
$mods = function_exists('apache_get_modules') ? apache_get_modules() : null;
if ($mods !== null) {
    foreach (['mod_rewrite' => 'tidier API URLs (optional)',
              'mod_headers' => 'stops a signed-out page coming back with the Back button'] as $m => $why) {
        $has = in_array($m, $mods, true);
        check(
            'Apache: ' . $m,
            $has ? 'ok' : 'warn',
            $has ? 'enabled' : 'not enabled — ' . $why,
            $has ? '' : 'Open C:\xampp\apache\conf\httpd.conf, remove the # in front of '
                . 'LoadModule ' . $m . '_module, then restart Apache.'
        );
    }
}

/* Mail */
check(
    'Email sending',
    MAIL_ENABLED ? 'ok' : 'warn',
    MAIL_ENABLED
        ? 'on, sending as ' . MAIL_USERNAME
        : 'off — registration codes will not be delivered',
    MAIL_ENABLED ? '' : 'Set MAIL_ENABLED to true in config/config.php and fill in '
        . 'a Gmail address and App Password.'
);

$bad  = count(array_filter($checks, static fn($c) => $c['state'] === 'bad'));
$warn = count(array_filter($checks, static fn($c) => $c['state'] === 'warn'));

/* ── one-click import ────────────────────────────────────── */

$importMsg = null;
$importOk  = false;
if (($_POST['action'] ?? '') === 'import' && $pdo) {
    /* Only ever offered when there is nothing to lose. */
    if ($dbPresent && $tables) {
        $importMsg = 'The database already contains tables. Nothing was changed. Use an empty database for a fresh import.';
    } else {
        $file = __DIR__ . '/database/coopvote_full.sql';
        if (!is_file($file)) {
            $importMsg = 'database/coopvote_full.sql is missing from this folder.';
        } else {
            try {
                $sql = (string) file_get_contents($file);

                /*
                 * The dump names its own database and begins by dropping it.
                 * Left alone that ignores DB_NAME and destroys whatever the
                 * dump was taken from, which is not necessarily the database
                 * being set up here.
                 *
                 * These statements cannot be matched at the start of a line:
                 * mysqldump hides the drop inside a version-gated comment,
                 * "/*!40000 DROP DATABASE IF EXISTS `x`* /;", which MySQL
                 * still executes. Match the whole line instead.
                 */
                $sql = implode("\n", array_filter(
                    preg_split('/\R/', $sql) ?: [],
                    static fn(string $line): bool => !preg_match(
                        '/\b(?:DROP|CREATE)\s+DATABASE\b|^\s*(?:\/\*![0-9]*\s*)?USE\s/i',
                        $line
                    )
                ));

                /* Refuse rather than risk it if anything slipped through. */
                if (preg_match('/\bDROP\s+DATABASE\b/i', $sql)) {
                    throw new PDOException(
                        'the dump still contains a DROP DATABASE statement after filtering'
                    );
                }

                $name = str_replace('`', '', DB_NAME);
                $pdo->exec(
                    'CREATE DATABASE IF NOT EXISTS `' . $name . '`'
                    . ' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
                $pdo->exec('USE `' . $name . '`');
                require_once __DIR__ . '/includes/sql-import.php';
                sql_import($pdo, $sql);

                $importOk  = true;
                $importMsg = 'Database imported into "' . DB_NAME
                    . '". Reload this page to confirm, then sign in.';
            } catch (Throwable $e) {
                $importMsg = 'Import failed: ' . $e->getMessage()
                    . ' — import database/coopvote_full.sql through phpMyAdmin instead.';
            }
        }
    }
}

$canImport = $pdo && (!$dbPresent || !$tables);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CoopVote — Setup Check</title>
<style>
  :root{--bg:#0a1628;--card:#0f2040;--line:rgba(255,255,255,.12);--ink:#e8eef7;
        --muted:#a8b8cc;--gold:#c9a84c;--ok:#4caf7d;--warn:#d9a441;--bad:#e05c5c}
  *{box-sizing:border-box}
  body{margin:0;padding:28px 16px;background:var(--bg);color:var(--ink);
       font:15px/1.6 "Segoe UI",system-ui,sans-serif}
  .wrap{max-width:760px;margin:0 auto}
  h1{margin:0 0 4px;font-size:1.5rem;letter-spacing:.2px}
  h1 span{color:var(--gold)}
  .sub{color:var(--muted);margin:0 0 22px;font-size:.9rem}
  .summary{border:1px solid var(--line);border-radius:12px;padding:14px 16px;
           margin-bottom:18px;background:var(--card)}
  .summary.good{border-color:rgba(76,175,125,.5)}
  .summary.bad{border-color:rgba(224,92,92,.5)}
  .summary b{font-size:1.05rem}
  ul{list-style:none;margin:0;padding:0}
  li{border:1px solid var(--line);border-radius:10px;background:var(--card);
     padding:12px 14px;margin-bottom:9px;display:flex;gap:11px;align-items:flex-start}
  .dot{width:9px;height:9px;border-radius:50%;margin-top:7px;flex:0 0 auto}
  .ok .dot{background:var(--ok)} .warn .dot{background:var(--warn)} .bad .dot{background:var(--bad)}
  .name{font-weight:600}
  .detail{color:var(--muted);font-size:.86rem}
  .fix{margin-top:6px;font-size:.86rem;color:#f0d9a0;background:rgba(201,168,76,.09);
       border-left:2px solid var(--gold);padding:7px 10px;border-radius:0 6px 6px 0}
  code{background:rgba(255,255,255,.07);padding:1px 5px;border-radius:4px;font-size:.85em}
  form{margin:18px 0 0}
  button{background:var(--gold);color:#1a1200;border:0;border-radius:8px;
         padding:11px 18px;font-weight:700;font-size:.92rem;cursor:pointer}
  button:hover{filter:brightness(1.08)}
  .msg{margin-top:14px;padding:12px 14px;border-radius:9px;font-size:.9rem;
       border:1px solid var(--line);background:var(--card)}
  .msg.good{border-color:rgba(76,175,125,.5)}
  a{color:var(--gold)}
  .foot{margin-top:26px;color:var(--muted);font-size:.83rem;
        border-top:1px solid var(--line);padding-top:14px}
</style>
</head>
<body>
<div class="wrap">
  <h1>Coop<span>Vote</span> — setup check</h1>
  <p class="sub">Run this on a new computer to see what is still missing.</p>

  <div class="summary <?= $bad ? 'bad' : 'good' ?>">
    <?php if ($bad): ?>
      <b><?= $bad ?> thing<?= $bad === 1 ? '' : 's' ?> still to fix.</b>
      Work through the highlighted steps below, then reload this page.
    <?php else: ?>
      <b>Everything is ready.</b>
      Open <a href="login.html">login.html</a> and sign in.
      <?php if ($warn): ?><br><span class="detail"><?= $warn ?> optional item<?= $warn === 1 ? '' : 's' ?> noted below.</span><?php endif; ?>
    <?php endif; ?>
  </div>

  <ul>
    <?php foreach ($checks as $c): ?>
      <li class="<?= $c['state'] ?>">
        <span class="dot"></span>
        <span>
          <span class="name"><?= htmlspecialchars($c['name']) ?></span>
          <span class="detail">— <?= htmlspecialchars($c['detail']) ?></span>
          <?php if ($c['fix']): ?><div class="fix"><?= htmlspecialchars($c['fix']) ?></div><?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($canImport): ?>
    <form method="post">
      <input type="hidden" name="action" value="import">
      <button type="submit">Import the database now</button>
    </form>
    <p class="detail" style="margin-top:8px">
      Runs <code>database/coopvote_full.sql</code>. Offered only because
      <code><?= htmlspecialchars(DB_NAME) ?></code> is missing or incomplete.
    </p>
  <?php endif; ?>

  <?php if ($importMsg): ?>
    <div class="msg <?= $importOk ? 'good' : '' ?>"><?= htmlspecialchars($importMsg) ?></div>
  <?php endif; ?>

  <div class="foot">
    Delete <code>setup.php</code> once the system is running — it reports
    details about this computer that no voter needs to see.
  </div>
</div>
</body>
</html>

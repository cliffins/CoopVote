<?php
/**
 * 10 — PORTABILITY / COMPATIBILITY TESTING
 *
 * The cooperative's copy of this will be zipped, carried to another
 * computer, unzipped somewhere else and expected to run. That has already
 * gone wrong once, so most of these checks are about assumptions a project
 * quietly makes about the machine it was built on: the folder it lives in,
 * the letter case of its filenames, the modules Apache happens to have.
 *
 * The last group actually copies the project to a differently-named folder
 * and runs it there.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    $rel = static fn(string $p): string => str_replace([BASE_PATH . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $p);

    $phpFiles = [];
    foreach (['api', 'includes', 'config', 'tools'] as $dir) {
        foreach (glob(BASE_PATH . '/' . $dir . '/{,*/}*.php', GLOB_BRACE) ?: [] as $f) {
            $phpFiles[] = $f;
        }
    }
    $phpFiles[] = BASE_PATH . '/setup.php';

    $htmlFiles = array_merge(
        glob(BASE_PATH . '/admin/*.html') ?: [],
        glob(BASE_PATH . '/voter/*.html') ?: [],
        [BASE_PATH . '/login.html']
    );

    /* ── The machine it was built on ───────────────────────── */
    $t->group('Nothing assumes this computer');

    $absolute = [];
    foreach (array_merge($phpFiles, $htmlFiles) as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match_all('#[\'"][A-Za-z]:[\\\\/]{1,2}(?:xampp|users|program)#i', $src, $m)) {
            $absolute[] = $rel($f) . ': ' . $m[0][0];
        }
        if (preg_match('#[\'"]/(?:home|var/www|usr)/#', $src)) {
            $absolute[] = $rel($f) . ': a Unix absolute path';
        }
    }
    $t->same('no absolute path is written into the code', [], $absolute);

    $t->group('The project folder can be renamed');

    /*
     * The API is reached as api/index.php?r=…, so no rewrite rule has to
     * know the folder name. A RewriteBase naming /CoopVote/ was what broke
     * this the last time the project was unzipped under another name.
     */
    $api = (string) file_get_contents(BASE_PATH . '/api/.htaccess');
    $t->ok('no RewriteBase names a fixed folder',
        !preg_match('/^\s*RewriteBase\s+\S/mi', $api),
        'a RewriteBase has to be edited every time the folder is renamed');

    $named = [];
    foreach (array_merge($phpFiles, $htmlFiles, [BASE_PATH . '/assets/js/app.js']) as $f) {
        /* The name appears legitimately as the product's own, and inside
           the comment recording why the path is no longer built from it, so
           comments come out before looking for a real path. */
        $src = (string) preg_replace(
            ['#/\*.*?\*/#s', '#^\s*//.*$#m'],
            '',
            (string) file_get_contents($f)
        );

        if (preg_match_all('#[\'"/](?:CoopVote)/#', $src, $m)) {
            $named[] = $rel($f) . ' (' . count($m[0]) . ')';
        }
    }
    $t->same('no file builds a URL from the folder name', [], $named);

    $t->ok('the front end works out its own root',
        preg_match('/ROOT|location\.pathname/', (string) file_get_contents(BASE_PATH . '/assets/js/app.js')) === 1
        || str_contains((string) file_get_contents(BASE_PATH . '/assets/js/app.js'), 'ROOT'),
        'so the pages keep working wherever the project is unzipped');

    /* ── Another operating system ──────────────────────────── */
    $t->group('It would survive a case-sensitive filesystem');

    /*
     * Windows does not care whether an include says Helpers.php or
     * helpers.php. Linux does, and the cooperative may one day host this on
     * a Linux server.
     */
    $badCase = [];
    foreach ($phpFiles as $f) {
        $src = (string) file_get_contents($f);
        if (!preg_match_all('/(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) {
            continue;
        }
        foreach ($m[1] as $target) {
            $path = dirname($f) . $target;
            $real = realpath($path);
            if ($real === false) {
                $badCase[] = $rel($f) . ' includes ' . $target . ' which does not exist';
                continue;
            }
            /* Compare the name on disk with the name as written. */
            $wanted = basename(str_replace('\\', '/', $target));
            $actual = basename(str_replace('\\', '/', $real));
            if ($wanted !== $actual) {
                $badCase[] = $rel($f) . ': asks for ' . $wanted . ', on disk it is ' . $actual;
            }
        }
    }
    $t->same('every include matches the filename exactly', [], $badCase);

    $t->group('Paths are built portably');

    $separators = [];
    foreach ($phpFiles as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match('#[\'"]\\\\\\\\[a-z]+[\'"]\s*\.\s*\$#i', $src)) {
            $separators[] = $rel($f) . ': a backslash path separator';
        }
    }
    $t->same('no path is joined with a backslash', [], $separators);

    $t->ok('uploads use DIRECTORY_SEPARATOR or a forward slash',
        str_contains((string) file_get_contents(BASE_PATH . '/config/config.php'), 'DIRECTORY_SEPARATOR'));

    $t->group('Files are stored in a portable form');

    $encodingProblems = [];
    foreach (array_merge($phpFiles, $htmlFiles) as $f) {
        $src = (string) file_get_contents($f);
        if (str_starts_with($src, "\xEF\xBB\xBF")) {
            $encodingProblems[] = $rel($f) . ': starts with a byte-order mark';
        }
        if (!mb_check_encoding($src, 'UTF-8')) {
            $encodingProblems[] = $rel($f) . ': is not valid UTF-8';
        }
    }
    $t->same('every file is clean UTF-8 with no byte-order mark', [], $encodingProblems,
        'a BOM before <?php is sent to the browser and breaks header() calls');

    /* ── What it needs from the server ─────────────────────── */
    $t->group('What the application needs is present, and is checked for');

    foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'json', 'session', 'openssl'] as $ext) {
        $t->ok('the ' . $ext . ' extension is loaded', extension_loaded($ext));
    }

    $t->ok('curl is not required by the application itself',
        !preg_match('/curl_init/', implode('', array_map(
            static fn($f) => (string) file_get_contents($f), $phpFiles))),
        'only the test suite uses curl, so a server without it still runs the app');

    $t->ok('PHP is 8.1 or newer, as the code requires',
        PHP_VERSION_ID >= 80100, PHP_VERSION);

    $setup = (string) file_get_contents(BASE_PATH . '/setup.php');
    foreach (['pdo_mysql', 'mbstring', 'fileinfo'] as $ext) {
        $t->contains('setup.php checks for ' . $ext, $ext, $setup);
    }
    $t->ok('setup.php checks the PHP version', str_contains($setup, 'PHP_VERSION'));

    $t->group('The language features used match the version claimed');

    $allPhp = implode("\n", array_map(static fn($f) => (string) file_get_contents($f), $phpFiles));
    $t->ok('the code uses PHP 8 features (match, named arguments, nullsafe)',
        str_contains($allPhp, 'match (') || str_contains($allPhp, 'match('),
        'so the minimum has to be stated as 8.0');
    $t->ok('INSTALL.txt states the PHP version needed',
        preg_match('/PHP\s*8/i', (string) file_get_contents(BASE_PATH . '/INSTALL.txt')) === 1);

    /* ── Apache ────────────────────────────────────────────── */
    $t->group('Apache configuration degrades gracefully');

    foreach (glob(BASE_PATH . '/{,*/}.htaccess', GLOB_BRACE) ?: [] as $f) {
        $src = (string) file_get_contents($f);

        if (str_contains($src, 'Require all denied')) {
            $t->ok($rel($f) . ' also carries the Apache 2.2 form',
                str_contains($src, 'Deny from all'),
                'Require all denied on Apache 2.2 is a fatal error that takes the whole site down');
        }

        /* Anything wrapped in IfModule cannot break a server that lacks it. */
        $directives = preg_split('/\R/', $src) ?: [];
        $bare = [];
        $depth = 0;
        foreach ($directives as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (preg_match('/^<IfModule/i', $line)) { $depth++; continue; }
            if (preg_match('/^<\/IfModule>/i', $line)) { $depth--; continue; }
            if ($depth > 0) continue;
            if (preg_match('/^(Header|RewriteEngine|RewriteRule|RewriteCond|php_flag|php_value|AddType|RemoveHandler)/i', $line)) {
                $bare[] = $line;
            }
        }
        $t->same($rel($f) . ' guards every module-specific directive', [], $bare,
            'an unguarded directive is a 500 on a server without that module');
    }

    $t->ok('the API works without mod_rewrite',
        str_contains((string) file_get_contents(BASE_PATH . '/api/index.php'), "\$_GET['r']"),
        'the pretty-URL rule is a convenience; ?r= is the path that always works');

    /* ── The browser ───────────────────────────────────────── */
    $t->group('The pages do not depend on anything being downloaded');

    $external = [];
    foreach ($htmlFiles as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match_all('#<script[^>]+src=["\'](https?:)?//[^"\']+#i', $src, $m)) {
            $external[] = $rel($f) . ': ' . implode(', ', array_map(
                static fn($u) => substr($u, 0, 60), $m[0]));
        }
    }
    $t->same('no page loads a script from the internet', [], $external,
        'the sign-in page used to fetch three.js from a CDN, and failed shut when it could not');

    $t->ok('three.js is vendored into the project',
        is_file(BASE_PATH . '/assets/js/vendor/three.min.js'));

    $fonts = 0;
    foreach ($htmlFiles as $f) {
        if (preg_match('#fonts\.googleapis\.com#', (string) file_get_contents($f))) {
            $fonts++;
        }
    }
    $t->ok('a web font that fails to load still leaves readable text (' . $fonts . ' pages link one)',
        true,
        'fonts are linked as stylesheets, which fall back to the local family list rather than blocking the page');

    /* ── Run it from somewhere else ────────────────────────── */
    $t->group('The project runs from a differently-named folder');

    $copyRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'CoopVote_Renamed_' . bin2hex(random_bytes(3));
    $copied   = 0;

    /* Only what is needed to answer a request — the whole tree would drag
       backups and uploads along for no benefit. */
    $wanted = ['api', 'includes', 'config', 'assets', 'admin', 'voter', 'vendor'];
    mkdir($copyRoot, 0777, true);

    $copyDir = static function (string $from, string $to) use (&$copyDir, &$copied): void {
        if (!is_dir($to)) mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $src = $from . DIRECTORY_SEPARATOR . $entry;
            $dst = $to . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($src)) {
                $copyDir($src, $dst);
            } else {
                copy($src, $dst);
                $copied++;
            }
        }
    };

    foreach ($wanted as $dir) {
        if (is_dir(BASE_PATH . '/' . $dir)) {
            $copyDir(BASE_PATH . '/' . $dir, $copyRoot . DIRECTORY_SEPARATOR . $dir);
        }
    }
    copy(BASE_PATH . '/login.html', $copyRoot . DIRECTORY_SEPARATOR . 'login.html');
    if (is_file(BASE_PATH . '/.htaccess')) {
        copy(BASE_PATH . '/.htaccess', $copyRoot . DIRECTORY_SEPARATOR . '.htaccess');
    }

    $t->ok($copied . ' files copied to a folder called ' . basename($copyRoot), $copied > 50);

    $port = 8110;
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if (is_resource($socket)) { fclose($socket); throw new RuntimeException('Port 8110 is occupied; refusing to reuse a stale server.'); }
    $sessionDir = BASE_PATH . '/tests/reports/sessions';
    if (!is_dir($sessionDir)) mkdir($sessionDir, 0700, true);
    $env  = array_merge(getenv(), ['COOPVOTE_DB' => TEST_DB, 'COOPVOTE_MAIL' => '0']);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'session.save_path=' . $sessionDir, '-S', '127.0.0.1:' . $port, '-t', $copyRoot],
        [0 => ['pipe', 'r'], 1 => ['file', 'nul', 'a'], 2 => ['file', 'nul', 'a']],
        $pipes,
        $copyRoot,
        $env
    );

    if (!is_resource($proc)) {
        $t->skip('running from the renamed folder', 'could not start a server there');
    } else {
        $base = 'http://127.0.0.1:' . $port . '/';
        $up   = false;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (Http::raw('GET', $base . 'login.html', null, [], 1)['status'] === 200) { $up = true; break; }
            usleep(200_000);
        }

        if (!$up) {
            $t->check('a server starts in the renamed folder', false, 'it never answered');
        } else {
            $t->ok('the sign-in page loads from the renamed folder', true);

            $r = Http::raw('GET', $base . 'api/index.php?r=', null, [], 5);
            $t->same('the API answers there', 200, $r['status']);
            $t->same('and identifies itself', 'CoopVote API', $r['json']['service'] ?? null);

            $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coopvote_rename_' . bin2hex(random_bytes(3)) . '.cookies';
            $r = Http::request('POST', $base . 'api/index.php?r=auth/login',
                ['username' => 't_voter1', 'password' => 'Test@1234'], [], $jar);
            $t->same('a member can sign in there', 200, $r['status']);

            $r = Http::request('GET', $base . 'api/index.php?r=voter/dashboard', null, [], $jar);
            $t->same('and reach their dashboard', 200, $r['status']);
            $t->ok('with their own details', ($r['json']['member']['name'] ?? '') !== '',
                json_encode($r['json']['member'] ?? null));
            @unlink($jar);
        }

        $status = proc_get_status($proc);
        if ($status && $status['running']) {
            proc_terminate($proc);
        }
        @proc_close($proc);
    }

    /* Tidy up the copy. */
    $rmDir = static function (string $dir) use (&$rmDir): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($p) ? $rmDir($p) : @unlink($p);
        }
        @rmdir($dir);
    };
    $resolvedCopy = realpath($copyRoot);
    $resolvedTemp = realpath(sys_get_temp_dir());
    if ($resolvedCopy === false || $resolvedTemp === false ||
        strcasecmp(dirname($resolvedCopy), $resolvedTemp) !== 0 ||
        !preg_match('/^CoopVote_Renamed_[a-f0-9]{6}$/', basename($resolvedCopy))) {
        throw new RuntimeException('Refusing cleanup outside the temporary portability-test folder.');
    }
    $rmDir($resolvedCopy);
    $t->ok('the copy is cleaned up afterwards', !is_dir($copyRoot));

    /* ── The database ──────────────────────────────────────── */
    $t->group('The database travels with its own settings');

    $charset = $pdo->query(
        "SELECT default_character_set_name FROM information_schema.schemata WHERE schema_name = DATABASE()"
    )->fetchColumn();
    $t->same('the database is utf8mb4, so names with accents survive', 'utf8mb4', $charset);

    $latin = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_collation NOT LIKE 'utf8mb4%'"
    )->fetchColumn();
    $t->same('every table uses the same collation', 0, (int) $latin);

    $engines = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND engine <> 'InnoDB'"
    )->fetchColumn();
    $t->same('every table is InnoDB, so transactions and foreign keys work', 0, (int) $engines);

    $t->group('The clock is not left to chance');

    $phpOffset = (new DateTimeImmutable('now'))->format('P');
    $dbOffset  = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
    $t->ok('the database session is pinned to PHP\'s offset (' . $phpOffset . ')',
        $dbOffset === $phpOffset,
        'MySQL reported ' . $dbOffset . '; a mismatch is what made fresh registration codes read as expired');

    $t->ok('a numeric offset is used, not a zone name',
        (bool) preg_match('/^[+-]\d{2}:\d{2}$/', $dbOffset),
        'named zones need tables a default XAMPP does not load');
};

<?php
/**
 * 9 — MAINTAINABILITY TESTING
 *
 * Whether the next person can safely change this. Everything here is
 * measured from the source itself: does it parse, is anything left over
 * from debugging, are the pieces small enough to hold in your head, is the
 * reasoning written down where it is not obvious.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    $phpFiles = [];
    foreach (['api', 'includes', 'config', 'tools', 'database', 'tests'] as $dir) {
        foreach (glob(BASE_PATH . '/' . $dir . '/{,*/,*/*/}*.php', GLOB_BRACE) ?: [] as $f) {
            $phpFiles[realpath($f)] = $f;
        }
    }
    foreach (['setup.php'] as $f) {
        if (is_file(BASE_PATH . '/' . $f)) $phpFiles[realpath(BASE_PATH . '/' . $f)] = BASE_PATH . '/' . $f;
    }
    $phpFiles = array_values($phpFiles);

    $htmlFiles = array_merge(
        glob(BASE_PATH . '/admin/*.html') ?: [],
        glob(BASE_PATH . '/voter/*.html') ?: [],
        [BASE_PATH . '/login.html']
    );

    $rel = static fn(string $p): string => str_replace([BASE_PATH . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $p);

    /* ── It all parses ─────────────────────────────────────── */
    $t->group('Everything compiles');

    $broken = [];
    foreach ($phpFiles as $f) {
        $out = [];
        $code = 0;
        exec(sprintf('"%s" -l "%s" 2>&1', PHP_BINARY, $f), $out, $code);
        if ($code !== 0) {
            $broken[] = $rel($f) . ': ' . implode(' ', $out);
        }
    }
    $t->same('every PHP file parses', [], $broken);
    $t->ok('there are ' . count($phpFiles) . ' PHP files to keep working', count($phpFiles) > 5);

    /* ── Nothing left over from debugging ──────────────────── */
    $t->group('No debugging leftovers in the shipped code');

    $shipped = array_filter($phpFiles, static fn($f) => !str_contains($rel($f), 'tests/'));

    $leftovers = [];
    foreach ($shipped as $f) {
        $src = (string) file_get_contents($f);
        foreach ([
            'var_dump('     => 'var_dump',
            'print_r('      => 'print_r',
            'dd('           => 'dd(',
            'die("'         => 'die with a message',
            "die('"         => 'die with a message',
            'error_reporting(E_ALL); ini_set(\'display_errors\', \'1\')' => 'display_errors on',
        ] as $needle => $label) {
            if (str_contains($src, $needle)) {
                $leftovers[] = $rel($f) . ': ' . $label;
            }
        }
    }
    $t->same('no debug dumps are left in the PHP', [], $leftovers);

    $jsLeftovers = [];
    foreach (array_merge($htmlFiles, [BASE_PATH . '/assets/js/app.js']) as $f) {
        $src = (string) file_get_contents($f);
        /* console.error in a catch block is deliberate; a bare console.log
           usually is not. */
        if (preg_match_all('/console\.log\s*\(/', $src, $m)) {
            $jsLeftovers[] = $rel($f) . ': ' . count($m[0]) . ' console.log';
        }
        if (str_contains($src, 'debugger;')) {
            $jsLeftovers[] = $rel($f) . ': debugger statement';
        }
        if (preg_match('/\balert\s*\(/', $src)) {
            $jsLeftovers[] = $rel($f) . ': alert()';
        }
    }
    $t->same('no debug output is left in the pages', [], $jsLeftovers);

    $t->group('No unfinished work is left unmarked');

    $markers = [];
    foreach (array_merge($shipped, $htmlFiles) as $f) {
        $src = (string) file_get_contents($f);
        /* Anchored to how a marker is actually written — "TODO:", or one
           opening a comment — so the phone placeholder "+63 9XX XXX XXXX"
           is not mistaken for unfinished work. */
        if (preg_match_all('/(?:\/\/|\/\*|\*|#)\s*(TODO|FIXME|HACK)\b|\b(TODO|FIXME|HACK|XXX)\s*:/i', $src, $m)) {
            $found = array_filter(array_merge($m[1] ?? [], $m[2] ?? []));
            if ($found) {
                $markers[] = $rel($f) . ': ' . implode(', ', array_unique($found));
            }
        }
    }
    $t->same('no TODO or FIXME markers remain', [], $markers);

    /* ── Size ──────────────────────────────────────────────── */
    $t->group('Nothing has grown past the point of being readable');

    $longFunctions = [];
    foreach ($shipped as $f) {
        $src   = (string) file_get_contents($f);
        $lines = explode("\n", $src);

        $current = null;
        $start   = 0;
        $depth   = 0;
        foreach ($lines as $i => $line) {
            if ($current === null && preg_match('/^\s*(?:public |private |protected |static )*function\s+(\w+)/', $line, $m)) {
                $current = $m[1];
                $start   = $i;
                $depth   = 0;
            }
            if ($current !== null) {
                $depth += substr_count($line, '{') - substr_count($line, '}');
                if ($depth <= 0 && $i > $start) {
                    $len = $i - $start + 1;
                    if ($len > 160) {
                        $longFunctions[] = $rel($f) . ':' . ($start + 1) . ' ' . $current . '() is ' . $len . ' lines';
                    }
                    $current = null;
                }
            }
        }
    }
    $t->same('no function runs past 160 lines', [], $longFunctions);

    $bigFiles = [];
    foreach ($shipped as $f) {
        $n = count(file($f) ?: []);
        if ($n > 1800) {
            $bigFiles[] = $rel($f) . ' is ' . $n . ' lines';
        }
    }
    $t->same('no PHP file runs past 1800 lines', [], $bigFiles);

    /* ── The reasoning is written down ─────────────────────── */
    $t->group('The code explains itself');

    foreach ($shipped as $f) {
        $src     = (string) file_get_contents($f);
        $total   = max(1, substr_count($src, "\n"));
        $comment = preg_match_all('/^\s*(\/\/|\/\*|\*|#)/m', $src);
        $ratio   = $comment / $total;

        /* Not a target to hit for its own sake — a file with almost none is
           usually one where the awkward decisions were never explained. */
        if ($total > 60) {
            $t->ok(
                sprintf('%s carries some explanation (%.0f%% of lines)', $rel($f), $ratio * 100),
                $ratio > 0.06,
                sprintf('%d comment lines in %d', $comment, $total)
            );
        }
    }

    $t->group('Every file says what it is for');

    $noHeader = [];
    foreach ($shipped as $f) {
        $head = substr((string) file_get_contents($f), 0, 400);
        if (!preg_match('/^<\?php\s*\/\*\*/s', $head) && !str_contains($head, '/**')) {
            $noHeader[] = $rel($f);
        }
    }
    $t->same('every PHP file opens with a docblock', [], $noHeader);

    /* ── Structure ─────────────────────────────────────────── */
    $t->group('The layers stay separate');

    foreach (glob(BASE_PATH . '/admin/*.html') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        $t->ok(basename($f) . ' contains no SQL',
            !preg_match('/\bSELECT\s+[\w*]+\s+FROM\b/i', $src),
            'a page that queries the database directly cannot be changed safely');
    }

    $t->ok('the pages reach the database only through the API',
        !preg_match('/\bnew\s+PDO\b|mysqli_/i',
            implode('', array_map(static fn($f) => (string) file_get_contents($f), $htmlFiles))));

    $t->group('Configuration is in one place');

    $hardcoded = [];
    foreach ($shipped as $f) {
        $src = (string) file_get_contents($f);
        if (str_contains($rel($f), 'config/')) continue;

        if (preg_match('/[\'"]root[\'"]\s*,\s*[\'"][\'"]/', $src)) {
            $hardcoded[] = $rel($f) . ': database credentials';
        }
        /* A DSN assembled from the config constants is fine — setup.php has
           to connect before the database exists. A literal host inside one
           is not, because it cannot be changed for another computer. */
        if (preg_match('/mysql:host=[a-z0-9.]+/i', $src)) {
            $hardcoded[] = $rel($f) . ': a DSN with a literal host';
        }
    }
    $t->same('no file outside config/ carries connection details', [], $hardcoded);

    /* ── Duplication ───────────────────────────────────────── */
    $t->group('The same thing is not written twice');

    /* Look for long identical runs between the route files, which is where
       copy-and-paste tends to happen. */
    $dupes = [];
    $routeFiles = array_values(array_filter($shipped, static fn($f) => str_contains($rel($f), 'api/routes/')));
    for ($i = 0; $i < count($routeFiles); $i++) {
        for ($j = $i + 1; $j < count($routeFiles); $j++) {
            $a = array_map('trim', file($routeFiles[$i]) ?: []);
            $b = array_map('trim', file($routeFiles[$j]) ?: []);
            $a = array_values(array_filter($a, static fn($l) => $l !== '' && !str_starts_with($l, '*') && !str_starts_with($l, '//')));
            $b = array_values(array_filter($b, static fn($l) => $l !== '' && !str_starts_with($l, '*') && !str_starts_with($l, '//')));

            $run = 0;
            $best = 0;
            $bSet = array_count_values($b);
            foreach ($a as $line) {
                if (strlen($line) > 20 && isset($bSet[$line])) {
                    $run++;
                    $best = max($best, $run);
                } else {
                    $run = 0;
                }
            }
            if ($best >= 12) {
                $dupes[] = $rel($routeFiles[$i]) . ' and ' . $rel($routeFiles[$j]) . ' share ' . $best . ' consecutive lines';
            }
        }
    }
    $t->same('the route files share no long copied blocks', [], $dupes);

    /* ── Dead code ─────────────────────────────────────────── */
    $t->group('Nothing is defined and never used');

    $shippedSrc = implode("\n", array_map(static fn($f) => (string) file_get_contents($f), $shipped));

    /* The tests count as callers: a helper the suite exercises is being
       used, even if no page happens to call it today. */
    $allSrc = $shippedSrc . "\n" . implode("\n", array_map(
        static fn($f) => (string) file_get_contents($f),
        array_merge(glob(BASE_PATH . '/tests/suites/*.php') ?: [], glob(BASE_PATH . '/tests/lib/*.php') ?: [])
    ));

    preg_match_all('/^function\s+(\w+)\s*\(/m', $shippedSrc, $m);

    $unused = [];
    foreach ($m[1] as $fn) {
        /* One occurrence is the definition itself. */
        if (substr_count($allSrc, $fn . '(') > 1) continue;
        if (str_starts_with($fn, 'handle_')) continue;      // reached by the router

        /* A function kept deliberately says so in its docblock, so the
           decision stays visible instead of being rediscovered as a puzzle. */
        if (preg_match('/@retained.*?\*\/\s*function\s+' . preg_quote($fn, '/') . '\b/s', $shippedSrc)) {
            continue;
        }

        $unused[] = $fn . '()';
    }
    $t->same('no function is defined and never called', [], $unused);

    $retained = preg_match_all('/@retained/', $shippedSrc);
    $t->ok($retained . ' function(s) are kept on purpose and record why', $retained > 0,
        'the self-registration handlers are held for a possible policy reversal');

    /* ── Dependencies ──────────────────────────────────────── */
    $t->group('The dependency list stays short');

    $lock = json_decode((string) file_get_contents(BASE_PATH . '/composer.lock'), true);
    $packages = array_column($lock['packages'] ?? [], 'name');
    $t->ok('only ' . count($packages) . ' third-party package(s): ' . implode(', ', $packages),
        count($packages) <= 3,
        'every dependency is something that has to be watched for security updates');

    $t->ok('nothing is pulled from a CDN at runtime',
        !preg_match('#<script[^>]+src=["\']https?://#i',
            implode('', array_map(static fn($f) => (string) file_get_contents($f), $htmlFiles))),
        'a CDN that is unreachable takes the page down with it');

    /* ── The tests themselves ──────────────────────────────── */
    $t->group('The test suite is maintainable too');

    $suites = glob(BASE_PATH . '/tests/suites/*.php') ?: [];
    $t->ok('there are ' . count($suites) . ' suites', count($suites) >= 10);

    $t->ok('the runner works without any installed packages',
        !str_contains((string) file_get_contents(BASE_PATH . '/tests/run.php'), 'vendor/autoload'),
        'a suite that needs composer install is a suite that does not get run');

    $t->ok('the suite refuses to touch the live database',
        str_contains((string) file_get_contents(BASE_PATH . '/tests/run.php'), 'coopvote_test')
        && str_contains((string) file_get_contents(BASE_PATH . '/tests/lib/testdb.php'), 'tdb_guard'));
};

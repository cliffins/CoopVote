<?php
/**
 * CoopVote — test runner
 *
 *   php tests/run.php            run everything
 *   php tests/run.php 1 3 7      run only suites 1, 3 and 7
 *   php tests/run.php --json     also write tests/reports/results.json
 *
 * Nothing here touches the cooperative's live database: the suites that
 * need data run against `coopvote_test`, rebuilt from the schema each time,
 * and the servers refuse to start if they are pointed anywhere else.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("The test suite runs from the command line: php tests/run.php\n");
}

/* The suite must never load the live database by accident. Setting this
   before config.php is included makes DB_NAME resolve to the scratch copy
   for this process and every server it starts. */
putenv('COOPVOTE_DB=coopvote_test');

/* A test run must not post mail to real addresses through the
   cooperative's account, and waiting five seconds on Gmail after every
   simulated vote would make the suite unusable besides. */
putenv('COOPVOTE_MAIL=0');

require __DIR__ . '/../includes/auth.php';   // pulls in config + helpers

/* The route files only define functions, so loading them here gives the
   module tests direct access to the query helpers (vt_*) without going
   through HTTP. */
require __DIR__ . '/../api/routes/voter.php';

require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/testdb.php';
require __DIR__ . '/lib/http.php';

/* Module tests exercise real session creation and regeneration. Keep CLI
   progress output buffered so it does not prematurely send session headers. */
ob_start();

if (DB_NAME !== TEST_DB) {
    exit("Refusing to run: DB_NAME is \"" . DB_NAME . "\", expected \"" . TEST_DB . "\".\n");
}

$SUITES = [
    '01' => ['Unit testing',                        '01_unit.php',            false],
    '02' => ['Component / module testing',          '02_module.php',          true],
    '03' => ['Integration testing',                 '03_integration.php',     true],
    '04' => ['System testing',                      '04_system.php',          true],
    '05' => ['Acceptance testing (UAT)',            '05_acceptance.php',      true],
    '06' => ['Performance testing',                 '06_performance.php',     true],
    '07' => ['Security testing',                    '07_security.php',        true],
    '08' => ['Usability testing',                   '08_usability.php',       true],
    '09' => ['Maintainability testing',             '09_maintainability.php', false],
    '10' => ['Portability / compatibility testing', '10_portability.php',     false],
    '11' => ['Regression testing',                  '11_regression.php',      true],
    '12' => ['Pilot / deployment validation',       '12_pilot.php',           false],
    '13' => ['Themes and admin approvals',          '13_management.php',      true],
];

$args     = array_slice($argv, 1);
$wantJson = in_array('--json', $args, true);
$only     = array_values(array_filter($args, static fn($a) => preg_match('/^\d+$/', $a)));
$only     = array_map(static fn($n) => str_pad($n, 2, '0', STR_PAD_LEFT), $only);

$selected = $only ? array_intersect_key($SUITES, array_flip($only)) : $SUITES;
$needsWeb = (bool) array_filter($selected, static fn($s) => $s[2]);

echo c('1;35', "\n  CoopVote — full test run\n");
echo '  ' . date('F j, Y · g:i A') . '   PHP ' . PHP_VERSION . "\n";

/* ── Build the scratch database ─────────────────────────── */
echo "\n  Building scratch database \"" . TEST_DB . "\"… ";
try {
    $pdo = tdb_rebuild();
    $FIXTURES = tdb_seed($pdo);
    echo c('32', "ready\n");
} catch (Throwable $e) {
    echo c('31', "failed\n  " . $e->getMessage() . "\n");
    exit(1);
}

/* ── Start the disposable servers ───────────────────────── */
$webUp = false;
if ($needsWeb) {
    echo '  Starting test web servers… ';
    $webUp = TestServer::start(4);
    echo $webUp
        ? c('32', 'ready on ' . implode(', ', TestServer::$ports) . "\n")
        : c('31', "failed\n");

    /* Stop them however this script ends. A suite that throws, or a parse
       error in the runner itself, used to leave servers listening — and the
       next run would then quietly report on the code those had loaded. */
    register_shutdown_function(static fn() => TestServer::stop());
}

/* ── Run ────────────────────────────────────────────────── */
$all = [];
foreach ($selected as $id => [$name, $file, $needsServer]) {
    $path = __DIR__ . '/suites/' . $file;
    /* PHP turns the numeric-looking keys '10'..'12' into integers, so the
       id has to be put back into the shape the report expects. */
    $suite = new Suite(str_pad((string) $id, 2, '0', STR_PAD_LEFT), $name);

    if (!is_file($path)) {
        $suite->skip($name, 'suite file not present');
    } elseif ($needsServer && !$webUp) {
        $suite->skip($name, 'the test web server could not be started');
    } else {
        /* Each suite gets a clean database so one cannot disturb another. */
        if ($needsServer || $id === '02') {
            $FIXTURES = tdb_seed($pdo);
        }
        $fn = require $path;
        try {
            $fn($suite, $pdo, $FIXTURES);
        } catch (Throwable $e) {
            $suite->check('suite ran to completion', false,
                get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    print_suite($suite);
    $all[$id] = $suite;
}

if ($webUp) {
    TestServer::stop();
}

/* ── Summary ────────────────────────────────────────────── */
$P = array_sum(array_map(static fn(Suite $s) => $s->passed(),  $all));
$F = array_sum(array_map(static fn(Suite $s) => $s->failed(),  $all));
$S = array_sum(array_map(static fn(Suite $s) => $s->skipped(), $all));

echo "\n" . str_repeat('═', 74) . "\n";
printf("  %-42s %6s %6s %6s\n", 'SUITE', 'PASS', 'FAIL', 'SKIP');
echo str_repeat('─', 74) . "\n";
foreach ($all as $id => $s) {
    printf(
        "  %s %-40s %6d %6s %6d\n",
        $id,
        $s->name,
        $s->passed(),
        $s->failed() ? c('31', (string) $s->failed()) : '0',
        $s->skipped()
    );
}
echo str_repeat('─', 74) . "\n";
printf("  %-42s %6d %6s %6d\n", 'TOTAL', $P, $F ? c('31', (string) $F) : '0', $S);
echo str_repeat('═', 74) . "\n";

echo $F === 0
    ? c('1;32', "\n  All " . $P . " checks passed.\n\n")
    : c('1;31', "\n  " . $F . " of " . ($P + $F) . " checks failed.\n\n");

if ($wantJson) {
    $out = ['generatedAt' => date('c'), 'php' => PHP_VERSION, 'suites' => []];
    foreach ($all as $id => $s) {
        $out['suites'][$id] = [
            'name' => $s->name, 'passed' => $s->passed(),
            'failed' => $s->failed(), 'skipped' => $s->skipped(),
            'results' => $s->results,
        ];
    }
    $out['totals'] = ['passed' => $P, 'failed' => $F, 'skipped' => $S];
    file_put_contents(
        __DIR__ . '/reports/results.json',
        json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    echo "  Wrote tests/reports/results.json\n\n";
}

exit($F === 0 ? 0 : 1);

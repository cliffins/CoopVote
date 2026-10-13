<?php
/**
 * CoopVote — HTTP client and disposable web servers for the test suite
 *
 * The API tests talk to a real server over real HTTP rather than calling the
 * route functions directly, because most of what can go wrong lives in that
 * gap: session cookies, status codes, headers, and the front controller's
 * own routing.
 *
 * The servers are PHP's built-in one, started on spare ports with
 * COOPVOTE_DB pointed at the scratch database. Apache is left alone, so the
 * cooperative's real installation keeps serving the real data throughout.
 */

declare(strict_types=1);

final class TestServer
{
    /** @var array<int,resource> */
    private static array $procs = [];
    /** @var int[] */
    public static array $ports = [];

    /**
     * Start one server per port. Several are started because PHP's built-in
     * server handles a single request at a time on Windows; the concurrency
     * tests need genuinely simultaneous requests, and separate processes
     * sharing one database provide exactly that.
     */
    public static function start(int $count = 4, int $firstPort = 8091): bool
    {
        $php  = PHP_BINARY;
        $root = BASE_PATH;

        /*
         * The child's environment is passed to proc_open explicitly. Setting
         * it through a `set X=Y&&` shell prefix looked like it worked — the
         * servers started and answered — but the variable never reached PHP,
         * so the suite silently ran against the cooperative's real database.
         * Passing $env removes the whole class of mistake, and start()
         * refuses to continue unless the running server confirms the
         * scratch database by name.
         */
        $env = array_merge(getenv(), ['COOPVOTE_DB' => TEST_DB, 'COOPVOTE_MAIL' => '0']);

        /*
         * A server left listening by an earlier run is worse than no server
         * at all: the new run would bind nothing, quietly reuse the old
         * process, and report on code that is no longer on disk. Refuse
         * instead, and say which port to clear.
         */
        for ($i = 0; $i < $count; $i++) {
            $port = $firstPort + $i;
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if (is_resource($sock)) {
                fclose($sock);
                fwrite(STDERR, PHP_EOL . 'ABORTING: port ' . $port . ' is already in use, most likely by a '
                    . 'test server left over from an earlier run.' . PHP_EOL
                    . 'Close it first, then run the suite again.' . PHP_EOL);
                self::stop();
                return false;
            }
        }

        $sessionDir = $root . '/tests/reports/sessions';
        if (!is_dir($sessionDir)) mkdir($sessionDir, 0700, true);
        for ($i = 0; $i < $count; $i++) {
            $port = $firstPort + $i;
            // Launch PHP directly; keep session writes inside the isolated workspace.
            $cmd = [$php, '-d', 'display_errors=0', '-d', 'session.save_path=' . $sessionDir,
                '-S', '127.0.0.1:' . $port, '-t', $root];

            $proc = proc_open(
                $cmd,
                [0 => ['pipe', 'r'], 1 => ['file', 'nul', 'a'],
                 2 => ['file', $root . '/tests/reports/server-' . $port . '.log', 'a']],
                $pipes,
                $root,
                $env
            );
            if (!is_resource($proc)) {
                return false;
            }
            self::$procs[] = $proc;
            self::$ports[] = $port;
        }

        /* Wait for every server to answer before handing out any URLs. */
        $deadline = microtime(true) + 20;
        $ready    = [];
        while (microtime(true) < $deadline && count($ready) < count(self::$ports)) {
            foreach (self::$ports as $idx => $port) {
                if (isset($ready[$port])) continue;
                $r = Http::raw('GET', self::url('api/index.php?r=', $idx), null, [], 1);
                if ($r['status'] > 0) $ready[$port] = true;
            }
            if (count($ready) < count(self::$ports)) usleep(200_000);
        }
        if (count($ready) < count(self::$ports)) {
            return false;
        }

        return self::assertScratchDatabase();
    }

    /**
     * Prove the servers are on the scratch database before a single test
     * runs. Without this the suite would happily write to live member data
     * if the environment failed to carry.
     */
    private static function assertScratchDatabase(): bool
    {
        $probe = BASE_PATH . '/tests/.dbprobe.php';
        file_put_contents(
            $probe,
            "<?php require __DIR__ . '/../config/config.php'; echo DB_NAME;"
        );

        $names = [];
        foreach (array_keys(self::$ports) as $idx) {
            $r = Http::raw('GET', self::url('tests/.dbprobe.php', $idx), null, [], 5);
            $names[] = trim($r['body']);
        }
        @unlink($probe);

        foreach ($names as $n) {
            if ($n !== TEST_DB) {
                fwrite(STDERR, PHP_EOL
                    . 'ABORTING: a test server reported database "' . $n . '", expected "'
                    . TEST_DB . '".' . PHP_EOL
                    . 'No tests were run; the live database was not touched.' . PHP_EOL);
                self::stop();
                return false;
            }
        }
        return true;
    }

    public static function stop(): void
    {
        foreach (self::$procs as $p) {
            $status = proc_get_status($p);
            if ($status && $status['running']) {
                // Array-form proc_open owns PHP directly, without a cmd.exe child.
                proc_terminate($p);
            }
            @proc_close($p);
        }
        $ports = self::$ports;
        self::$procs = [];
        self::$ports = [];

        /* Windows releases a listening socket a moment after the process
           goes; without this wait the next run can trip its own guard. */
        $deadline = microtime(true) + 5;
        foreach ($ports as $port) {
            while (microtime(true) < $deadline) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
                if (!is_resource($sock)) break;
                fclose($sock);
                usleep(150_000);
            }
        }
    }

    /** Base URL for one of the running servers (round-robin). */
    public static function url(string $path = '', ?int $index = null): string
    {
        static $n = 0;
        $ports = self::$ports ?: [8091];
        $port  = $ports[$index !== null ? $index % count($ports) : ($n++ % count($ports))];
        return 'http://127.0.0.1:' . $port . '/' . ltrim($path, '/');
    }
}

/** A cookie jar plus the handful of verbs the suite needs. */
final class Http
{
    public string $jar;

    public function __construct(public string $label = 'anon')
    {
        $this->jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'coopvote_test_' . $label . '_' . bin2hex(random_bytes(3)) . '.cookies';
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** One API call. Returns ['status'=>int,'json'=>array|null,'body'=>string,'headers'=>string,'ms'=>float]. */
    public function api(string $method, string $route, ?array $body = null, array $headers = []): array
    {
        return self::request($method, TestServer::url('api/index.php?r=' . $route), $body, $headers, $this->jar);
    }

    /** A plain page fetch (no API). */
    public function page(string $path): array
    {
        return self::request('GET', TestServer::url($path), null, [], $this->jar);
    }

    /** Sign in, returning the response. */
    public function login(string $identifier, string $password = 'Test@1234'): array
    {
        return $this->api('POST', 'auth/login', ['username' => $identifier, 'password' => $password]);
    }

    public function logout(): array
    {
        return $this->api('POST', 'auth/logout');
    }

    /** Request without any cookie jar. */
    public static function raw(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 20): array
    {
        return self::request($method, $url, $body, $headers, null, $timeout);
    }

    public static function request(
        string $method,
        string $url,
        ?array $body = null,
        array $headers = [],
        ?string $jar = null,
        int $timeout = 20
    ): array {
        $ch = curl_init($url);
        $hdr = array_merge(['Accept: application/json'], $headers);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        // Trust only the configured local development CA for Apache smoke checks.
        // Peer/hostname verification stays enabled; external URLs use normal trust.
        if (str_starts_with($url, 'https://localhost/') && is_file(BASE_PATH.'/config/security-local.php')) {
            $security = require BASE_PATH.'/config/security-local.php';
            if (!empty($security['db_ssl_ca'])) $opts[CURLOPT_CAINFO] = $security['db_ssl_ca'];
        }
        if ($jar !== null) {
            $opts[CURLOPT_COOKIEJAR]  = $jar;
            $opts[CURLOPT_COOKIEFILE] = $jar;
        }
        if ($body !== null) {
            if (array_filter($body, static fn($value) => $value instanceof CURLFile)) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            } else {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
                $hdr[] = 'Content-Type: application/json';
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $hdr;

        curl_setopt_array($ch, $opts);

        $t0   = microtime(true);
        $resp = curl_exec($ch);
        $ms   = (microtime(true) - $t0) * 1000;

        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err      = curl_error($ch);
        curl_close($ch);

        $resp    = is_string($resp) ? $resp : '';
        $rawHdr  = substr($resp, 0, $headerSz);
        $rawBody = substr($resp, $headerSz);

        return [
            'status'  => $status,
            'headers' => $rawHdr,
            'body'    => $rawBody,
            'json'    => json_decode($rawBody, true),
            'ms'      => $ms,
            'error'   => $err,
        ];
    }

    /**
     * Fire several requests at the same moment and return them in order.
     * Used for the double-vote race and the load measurements.
     *
     * @param array<int,array{0:string,1:string,2:?array,3:?string}> $calls
     *        Each entry is [method, url, body|null, cookieJar|null].
     */
    public static function parallel(array $calls): array
    {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($calls as $i => [$method, $url, $body, $jar]) {
            $ch  = curl_init($url);
            $hdr = ['Accept: application/json'];
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
            ];
            if ($jar !== null) {
                $opts[CURLOPT_COOKIEFILE] = $jar;
                $opts[CURLOPT_COOKIEJAR]  = $jar;
            }
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
                $hdr[] = 'Content-Type: application/json';
            }
            $opts[CURLOPT_HTTPHEADER] = $hdr;
            curl_setopt_array($ch, $opts);

            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $t0 = microtime(true);
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running > 0);
        $elapsed = (microtime(true) - $t0) * 1000;

        $out = [];
        foreach ($handles as $i => $ch) {
            $resp     = (string) curl_multi_getcontent($ch);
            $headerSz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $out[$i] = [
                'status'  => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'ms'      => curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000,
                'headers' => substr($resp, 0, $headerSz),
                'body'    => substr($resp, $headerSz),
                'json'    => json_decode(substr($resp, $headerSz), true),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        $out['_wallMs'] = $elapsed;
        return $out;
    }
}

/**
 * Drive a headless browser and return its measurements.
 *
 * Returns ['unavailable' => reason] when no browser could be started, so a
 * suite can skip rather than report a defect in the application.
 */
function browser_probe(array $spec): array
{
    if (getenv('COOPVOTE_SKIP_BROWSER') === '1') {
        return ['unavailable' => 'Browser checks explicitly skipped: no approved browser is available in this session.'];
    }
    $node = null;
    foreach (['node', 'node.exe'] as $candidate) {
        $which = @shell_exec('where ' . $candidate . ' 2>nul');
        if (is_string($which) && trim($which) !== '') {
            $node = trim(explode("\n", trim($which))[0]);
            break;
        }
    }
    if ($node === null) {
        return ['unavailable' => 'Node.js is not installed, so the browser checks cannot run'];
    }

    $specFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coopvote_browser_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($specFile, json_encode($spec, JSON_UNESCAPED_SLASHES));

    $cmd = sprintf(
        '"%s" "%s" "%s"',
        $node,
        BASE_PATH . '/tests/lib/browser.js',
        $specFile
    );

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, BASE_PATH);
    if (!is_resource($proc)) {
        @unlink($specFile);
        return ['unavailable' => 'could not start the browser probe'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($proc);
    @unlink($specFile);

    $data = json_decode((string) $stdout, true);
    if (!is_array($data)) {
        return ['unavailable' => 'the browser probe returned nothing usable: '
            . substr(trim($stderr ?: $stdout), 0, 200)];
    }
    return $data;
}

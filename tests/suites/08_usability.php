<?php
/**
 * 8 — USABILITY TESTING
 *
 * Two kinds of check. The first reads the pages as files: does each one
 * declare a language, a title, a viewport; is every field labelled. The
 * second opens them in a real browser and looks at what a member would
 * actually meet — including at phone width, since a cooperative's members
 * will mostly arrive on a phone.
 *
 * The wording of what the system says to people is treated as part of the
 * interface here, because for most members it is the whole of it.
 */

declare(strict_types=1);

return static function (Suite $t, PDO $pdo, array $fx): void {

    $pages = array_merge(
        glob(BASE_PATH . '/admin/*.html') ?: [],
        glob(BASE_PATH . '/voter/*.html') ?: [],
        [BASE_PATH . '/login.html']
    );

    /* ── The page shell ────────────────────────────────────── */
    $t->group('Every page declares the basics');

    foreach ($pages as $file) {
        $name = basename($file);
        $html = (string) file_get_contents($file);

        $t->matches($name . ' declares its language', '/<html[^>]+lang=/i', $html);
        $t->matches($name . ' declares a character set', '/<meta[^>]+charset=/i', $html);
        $t->matches($name . ' sets a viewport, so it fits a phone',
            '/<meta[^>]+name=["\']viewport["\']/i', $html);
        $t->matches($name . ' has a title', '/<title>[^<]{3,}<\/title>/i', $html);
    }

    $t->group('Titles name the page, not just the product');

    $titles = [];
    foreach ($pages as $file) {
        if (preg_match('/<title>([^<]+)<\/title>/i', (string) file_get_contents($file), $m)) {
            $titles[basename($file)] = trim($m[1]);
        }
    }
    $t->same('every page has a distinct title', count($titles), count(array_unique($titles)),
        implode(' | ', $titles));

    /* ── What the system says to people ────────────────────── */
    $t->group('Messages are written for members, not for programmers');

    $voter = new Http('use_voter');
    $voter->login('t_voter1');
    $anon  = new Http('use_anon');

    $messages = [];
    $messages['empty sign-in']      = $anon->api('POST', 'auth/login', ['username' => '', 'password' => ''])['json']['error'] ?? '';
    $messages['wrong password']     = $anon->api('POST', 'auth/login', ['username' => 't_voter1', 'password' => 'nope'])['json']['error'] ?? '';
    $messages['unknown route']      = $anon->api('GET', 'no/such/route')['json']['error'] ?? '';
    $messages['not signed in']      = $anon->api('GET', 'voter/dashboard')['json']['error'] ?? '';
    $messages['empty ballot']       = $voter->api('POST', 'voter/vote', ['electionId' => $fx['live'], 'votes' => []])['json']['error'] ?? '';
    $messages['self-registration']  = $anon->api('POST', 'auth/register', ['username' => 'x'])['json']['error'] ?? '';

    foreach ($messages as $label => $text) {
        $t->ok('"' . $label . '" says something', trim($text) !== '');

        $t->ok('"' . $label . '" reads as a sentence',
            (bool) preg_match('/^[A-Z].*[.!?]$/s', trim($text)),
            'got: ' . $text);

        $t->ok('"' . $label . '" avoids technical jargon',
            !preg_match('/\b(SQL|PDO|exception|stack|null|undefined|422|500|fatal|query)\b/i', $text),
            'got: ' . $text);

        $t->ok('"' . $label . '" has no file path or line number in it',
            !preg_match('/\.php|\bline \d|C:\\\\/i', $text),
            'got: ' . $text);
    }

    $t->group('A refusal says what to do next');

    $closed = $anon->api('POST', 'auth/register', ['username' => 'x'])['json']['error'] ?? '';
    $t->contains('a member told they cannot register is pointed somewhere',
        'contact them', $closed);

    $admin = new Http('use_admin');
    $admin->login('t_admin');

    $r = $admin->api('POST', 'elections', [
        'title' => 'Ends Before It Starts',
        'startDate' => '2030-06-01T09:00', 'endDate' => '2030-01-01T09:00',
    ]);
    $t->contains('an impossible date range explains the rule',
        'after the start date', json_encode($r['json']['fields'] ?? []));

    $r = $admin->api('PATCH', 'elections/' . $fx['draft'] . '/activate');
    $t->ok('starting an empty election says what is missing first',
        str_contains(strtolower((string) ($r['json']['error'] ?? '')), 'add at least one'),
        (string) ($r['json']['error'] ?? ''));

    $t->group('Validation names the field that is wrong');

    $r = $admin->api('POST', 'elections', ['title' => '', 'startDate' => '', 'endDate' => '']);
    $fields = $r['json']['fields'] ?? [];
    $t->ok('each empty field is named individually',
        isset($fields['title'], $fields['startDate'], $fields['endDate']),
        implode(', ', array_keys($fields)));
    foreach ($fields as $k => $msg) {
        $t->ok('the message for "' . $k . '" is a sentence',
            (bool) preg_match('/^[A-Z].*[.!?]$/', trim((string) $msg)), (string) $msg);
    }

    /* ── In a real browser ─────────────────────────────────── */
    $t->group('The pages in a browser');

    $base   = rtrim(TestServer::url(''), '/') . '/';
    $probe  = browser_probe([
        'base'   => $base,
        'port'   => 9334,
        'settle' => 2200,
        'login'  => ['username' => 't_voter1', 'password' => 'Test@1234'],
        'pages'  => [
            'voter/dashboard.html', 'voter/election.html', 'voter/candidates.html',
            'voter/ballot.html', 'voter/results.html', 'voter/profile.html',
        ],
        'widths' => [390, 768],
        'responsivePages' => ['voter/dashboard.html', 'voter/election.html', 'voter/candidates.html', 'voter/ballot.html', 'voter/results.html'],
    ]);

    if (isset($probe['unavailable'])) {
        $t->skip('browser checks', (string) $probe['unavailable']);
        return;
    }

    $t->same('signing in takes a member to their dashboard',
        true, str_contains((string) ($probe['landedOn'] ?? ''), 'voter/dashboard.html'),
        (string) ($probe['landedOn'] ?? ''));

    foreach ($probe['pages'] ?? [] as $page => $m) {
        $short = basename($page);
        if (in_array($short, ['election.html', 'candidates.html', 'ballot.html'], true)) {
            $t->ok($short . ' displays the election selector', trim($m['picker'] ?? '') !== '');
        }

        $t->ok($short . ' renders content', ($m['words'] ?? 0) >= 15,
            ($m['words'] ?? 0) . ' words — a near-empty page usually means the data never arrived');

        $t->same($short . ' raises no script errors', [], $m['consoleErrors'] ?? []);
        $t->same($short . ' loads every asset it asks for', [], $m['failedRequests'] ?? []);

        $t->ok($short . ' does not show the "server is down" banner',
            !($m['hasServerBanner'] ?? false));

        $t->same($short . ' has exactly one main heading', 1, $m['h1Count'] ?? 0);

        $t->same($short . ' labels every field it shows', [], $m['unlabelled'] ?? []);
        $t->same($short . ' gives every image alternative text', 0, $m['imgsNoAlt'] ?? 0);
        $t->same($short . ' has no button a screen reader cannot name', 0, $m['namelessButtons'] ?? 0);

        $t->ok($short . ' can be moved through by keyboard', ($m['focusables'] ?? 0) > 3,
            ($m['focusables'] ?? 0) . ' focusable controls');

        $t->ok($short . ' loads the shared runtime', ($m['escAvailable'] ?? false) === true,
            'esc() missing means app.js did not load, and nothing else on the page will work');
    }

    $t->group('The pages fit a phone');

    foreach ($probe['widths'] ?? [] as $width => $pagesAtWidth) {
        foreach ($pagesAtWidth as $page => $m) {
            $doc = (int) ($m['docWidth'] ?? 0);
            $win = (int) ($m['winWidth'] ?? 0);

            /* A couple of pixels of rounding is not a sideways scroll. */
            $t->ok(
                sprintf('%s fits %dpx without scrolling sideways', basename($page), $width),
                $doc <= $win + 2,
                sprintf('the page is %dpx wide in a %dpx window', $doc, $win)
            );
            $t->ok(sprintf('%s still shows its content at %dpx', basename($page), $width),
                ($m['words'] ?? 0) >= 15, ($m['words'] ?? 0) . ' words');
        }
    }
};

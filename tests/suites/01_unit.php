<?php
/**
 * 1 — UNIT TESTING
 *
 * The smallest testable pieces: functions that take values and return
 * values, with no database, session or network involved.
 */

declare(strict_types=1);

return static function (Suite $t): void {

    /* ── Name splitting ────────────────────────────────────── */
    $t->group('split_name() — the UI wants first/last, members stores one string');

    $t->same('plain two-part name', ['first' => 'Juan', 'last' => 'Cruz'], split_name('Juan Cruz'));
    $t->same('middle initial is dropped', ['first' => 'Juan', 'last' => 'Dela Cruz'], split_name('Juan D. Dela Cruz'));
    $t->same('single word gives an empty surname', ['first' => 'Madonna', 'last' => ''], split_name('Madonna'));
    $t->same('empty string is handled', ['first' => '', 'last' => ''], split_name(''));
    $t->same('runs of spaces collapse', ['first' => 'Ana', 'last' => 'Reyes'], split_name('  Ana    Reyes  '));
    $t->same('three-part name keeps both surnames', ['first' => 'Maria', 'last' => 'Santos Cruz'], split_name('Maria Santos Cruz'));
    $t->same('a two-part name keeps an initial as the surname', ['first' => 'Jose', 'last' => 'R.'], split_name('Jose R.'));

    /* ── Age ───────────────────────────────────────────────── */
    $t->group('age_from_dob()');

    $eighteen = (new DateTimeImmutable('-18 years'))->format('Y-m-d');
    $t->same('exactly 18 today', 18, age_from_dob($eighteen));

    $almost = (new DateTimeImmutable('-18 years +1 day'))->format('Y-m-d');
    $t->same('a day short of 18 is 17', 17, age_from_dob($almost));

    $t->same('null date of birth', null, age_from_dob(null));
    $t->same('empty date of birth', null, age_from_dob(''));
    $t->same('nonsense date is rejected', null, age_from_dob('not-a-date'));

    /* ── Election status ───────────────────────────────────── */
    $t->group('election_status() — DB enum + clock become the UI label');

    $mk = static fn(string $status, int $startOffset, int $endOffset): array => [
        'status'         => $status,
        'start_datetime' => date('Y-m-d H:i:s', time() + $startOffset),
        'end_datetime'   => date('Y-m-d H:i:s', time() + $endOffset),
    ];

    $t->same('draft stays draft even inside its window', 'Draft', election_status($mk('draft', -60, 60)));
    $t->same('cancelled', 'Cancelled', election_status($mk('cancelled', -60, 60)));
    $t->same('postponed', 'Postponed', election_status($mk('postponed', -60, 60)));
    $t->same('published and inside the window is Active', 'Active', election_status($mk('published', -60, 60)));
    $t->same('published and not started is Upcoming', 'Upcoming', election_status($mk('published', 60, 120)));
    $t->same('published and finished is Closed', 'Closed', election_status($mk('published', -120, -60)));

    /* The end moment is exclusive — closing an election stamps end_datetime
       with NOW(), and it has to read Closed on the very next page load. */
    $t->same('the end second itself is Closed', 'Closed', election_status($mk('published', -60, 0)));
    $t->same('the start second is already Active', 'Active', election_status($mk('published', 0, 60)));

    $t->same('is_election_live agrees for a live election', true, is_election_live($mk('published', -60, 60)));
    $t->same('is_election_live is false for a draft', false, is_election_live($mk('draft', -60, 60)));
    $t->same('is_election_live is false once closed', false, is_election_live($mk('published', -120, -60)));

    /* ── Lockout schedule ──────────────────────────────────── */
    $t->group('lockout_seconds() — each lockout is longer than the last');

    $t->same('first lockout', 15, lockout_seconds(1));
    $t->same('second lockout', 30, lockout_seconds(2));
    $t->same('third lockout', 60, lockout_seconds(3));
    $t->same('a fourth stays at the longest wait', 60, lockout_seconds(4));
    $t->same('a hundredth stays at the longest wait', 60, lockout_seconds(100));
    $t->same('zero is treated as the first', 15, lockout_seconds(0));
    $t->same('a negative number is treated as the first', 15, lockout_seconds(-5));

    $t->group('format_wait()');
    $t->same('one second is singular', '1 second', format_wait(1));
    $t->same('fifteen seconds', '15 seconds', format_wait(15));
    $t->same('sixty seconds becomes a minute', '1 minute', format_wait(60));
    $t->same('two minutes', '2 minutes', format_wait(120));
    $t->same('ninety seconds rounds to 2 minutes', '2 minutes', format_wait(90));

    /* ── Email masking ─────────────────────────────────────── */
    $t->group('mask_email() — confirm the address without printing it');

    $masked = mask_email('clifford@gmail.com');
    $t->ok('keeps the first letter', str_starts_with($masked, 'c'), $masked);
    $t->ok('keeps the domain', str_ends_with($masked, '@gmail.com'), $masked);
    $t->notContains('hides the middle of the local part', 'lifford', $masked);
    $t->same('an address with no @ is returned unchanged', 'notanemail', mask_email('notanemail'));
    $t->ok('a two-letter local part is still masked', !str_contains(mask_email('ab@x.com'), 'ab@'), mask_email('ab@x.com'));

    /* ── Receipts ──────────────────────────────────────────── */
    $t->group('generate_receipt()');

    $r = generate_receipt();
    $t->matches('shape is CV- plus 8 characters', '/^CV-[A-Z2-9]{8}$/', $r);
    $t->ok('avoids I, O, 0 and 1, which people misread', !preg_match('/[IO01]/', substr($r, 3)), $r);

    $seen = [];
    for ($i = 0; $i < 5000; $i++) {
        $seen[generate_receipt()] = true;
    }
    $t->ok('5000 receipts came out unique', count($seen) === 5000, count($seen) . ' distinct');

    /* ── Date formatting ───────────────────────────────────── */
    $t->group('formatters');

    $t->same('fmt_date', 'January 15, 2026', fmt_date('2026-01-15 15:00:00'));
    $t->same('fmt_date of null', '—', fmt_date(null));
    $t->same('fmt_date of an unparseable value', '—', fmt_date('rubbish'));
    $t->same('fmt_datetime', 'January 15, 2026 · 3:00 PM', fmt_datetime('2026-01-15 15:00:00'));
    $t->same('fmt_month_year', 'March 2024', fmt_month_year('2024-03-09'));
    $t->same('fmt_iso of null', null, fmt_iso(null));
    $t->matches('fmt_iso is ISO-8601 with an offset', '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) fmt_iso('2026-01-15 15:00:00'));

    $t->group('fmt_relative()');
    $t->same('a moment ago', 'just now', fmt_relative(date('Y-m-d H:i:s', time() - 2)));
    $t->same('30 seconds', '30 secs ago', fmt_relative(date('Y-m-d H:i:s', time() - 30)));
    $t->same('one minute is singular', '1 min ago', fmt_relative(date('Y-m-d H:i:s', time() - 60)));
    $t->same('five minutes', '5 mins ago', fmt_relative(date('Y-m-d H:i:s', time() - 300)));
    $t->same('one hour is singular', '1 hour ago', fmt_relative(date('Y-m-d H:i:s', time() - 3600)));
    $t->same('three hours', '3 hours ago', fmt_relative(date('Y-m-d H:i:s', time() - 10800)));
    $t->same('one day is singular', '1 day ago', fmt_relative(date('Y-m-d H:i:s', time() - 86400)));
    $t->same('a future timestamp does not read as negative', 'just now', fmt_relative(date('Y-m-d H:i:s', time() + 600)));
    $t->same('older than a month falls back to a date', fmt_date('2020-01-01'), fmt_relative('2020-01-01 00:00:00'));

    /* ── Device labels ─────────────────────────────────────── */
    $t->group('device_label() — for the security log');

    $t->same('iPhone', 'iPhone', device_label('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)'));
    $t->same('iPad', 'iPad', device_label('Mozilla/5.0 (iPad; CPU OS 17_0)'));
    $t->same('Android', 'Android', device_label('Mozilla/5.0 (Linux; Android 14; Pixel)'));
    $t->same('Windows', 'Windows', device_label('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'));
    $t->same('Mac', 'Mac', device_label('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)'));
    $t->same('empty user-agent', 'Unknown', device_label(''));

    /* An Android phone reports Linux too; the more specific label must win. */
    $t->same('Android is not mislabelled as Linux', 'Android', device_label('Mozilla/5.0 (Linux; Android 13)'));

    /* ── Role landing pages ────────────────────────────────── */
    $t->group('role_home()');

    $t->same('a voter lands on the member dashboard', 'voter/dashboard.html', role_home('voter'));
    $t->same('an admin lands on the back office', 'admin/dashboard.html', role_home('admin'));
    /* Two roles now, so everything that is not a voter is the back office.
       An unknown slug lands there too, which is the safe way round: a
       member sent to the admin dashboard is refused by the API on arrival,
       whereas an administrator sent to the member pages sees a broken
       page and no way back. */
    $t->same('anything else lands on the back office too',
        'admin/dashboard.html', role_home('no_such_role'));

    /* ── Request body parsing ──────────────────────────────── */
    $t->group('input_str() / input()');

    $t->same('a missing key falls back to the default', 'fallback', input_str('no_such_key', 'fallback'));
    $t->same('a missing key with no default is empty', '', input_str('no_such_key'));
    $t->same('input() returns its default', 42, input('no_such_key', 42));
};

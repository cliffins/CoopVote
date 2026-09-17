<?php
/**
 * CoopVote — minimal test harness
 *
 * Deliberately dependency-free. The cooperative's machine has no Composer
 * packages beyond PHPMailer and no internet on election day, so a suite
 * that needed `composer install` to run would not be run at all.
 */

declare(strict_types=1);

final class Suite
{
    public array $results = [];
    private string $group = '';

    public function __construct(public string $id, public string $name) {}

    /** Start a named group of related checks. */
    public function group(string $g): void
    {
        $this->group = $g;
    }

    /** Record one check. */
    public function check(string $name, bool $pass, string $detail = ''): bool
    {
        $this->results[] = [
            'group'  => $this->group,
            'name'   => $name,
            'pass'   => $pass,
            'detail' => $detail,
        ];
        return $pass;
    }

    public function ok(string $name, bool $cond, string $detail = ''): bool
    {
        return $this->check($name, $cond, $detail);
    }

    /**
     * $note explains why the check matters. It is shown only on failure,
     * where knowing what the expectation was for is the thing that saves
     * the reader a trip through the source.
     */
    public function same(string $name, mixed $expected, mixed $actual, string $note = ''): bool
    {
        $pass = $expected === $actual;
        return $this->check($name, $pass, $pass ? '' :
            'expected ' . self::show($expected) . ', got ' . self::show($actual)
            . ($note !== '' ? ' — ' . $note : ''));
    }

    public function equals(string $name, mixed $expected, mixed $actual): bool
    {
        $pass = $expected == $actual;
        return $this->check($name, $pass, $pass ? '' :
            'expected ' . self::show($expected) . ', got ' . self::show($actual));
    }

    public function contains(string $name, string $needle, string $haystack): bool
    {
        $pass = $needle !== '' && str_contains($haystack, $needle);
        return $this->check($name, $pass, $pass ? '' :
            'did not find ' . self::show($needle) . ' in ' . self::show(substr($haystack, 0, 160)));
    }

    public function notContains(string $name, string $needle, string $haystack): bool
    {
        $pass = !str_contains($haystack, $needle);
        return $this->check($name, $pass, $pass ? '' : 'found ' . self::show($needle));
    }

    public function matches(string $name, string $pattern, string $subject): bool
    {
        $pass = (bool) preg_match($pattern, $subject);
        return $this->check($name, $pass, $pass ? '' :
            $pattern . ' did not match ' . self::show(substr($subject, 0, 160)));
    }

    /** Assert a callable throws. */
    public function throws(string $name, callable $fn): bool
    {
        try {
            $fn();
            return $this->check($name, false, 'no exception was thrown');
        } catch (Throwable $e) {
            return $this->check($name, true);
        }
    }

    public function skip(string $name, string $why): void
    {
        $this->results[] = ['group' => $this->group, 'name' => $name, 'pass' => null, 'detail' => $why];
    }

    public static function show(mixed $v): string
    {
        if (is_bool($v))   return $v ? 'true' : 'false';
        if (is_null($v))   return 'null';
        if (is_scalar($v)) return var_export($v, true);
        return json_encode($v, JSON_UNESCAPED_SLASHES) ?: gettype($v);
    }

    public function passed(): int { return count(array_filter($this->results, fn($r) => $r['pass'] === true)); }
    public function failed(): int { return count(array_filter($this->results, fn($r) => $r['pass'] === false)); }
    public function skipped(): int { return count(array_filter($this->results, fn($r) => $r['pass'] === null)); }
}

/** ANSI helpers — plain text when the output is redirected. */
function c(string $code, string $s): string
{
    static $tty = null;
    $tty ??= PHP_SAPI === 'cli'
        && getenv('NO_COLOR') === false
        && function_exists('stream_isatty')
        && @stream_isatty(STDOUT);
    return $tty ? "\033[{$code}m{$s}\033[0m" : $s;
}

function print_suite(Suite $s): void
{
    $head = sprintf('%s  %s', $s->id, strtoupper($s->name));
    echo "\n" . c('1;36', $head) . "\n" . str_repeat('─', 74) . "\n";

    $lastGroup = null;
    foreach ($s->results as $r) {
        if ($r['group'] !== $lastGroup) {
            if ($r['group'] !== '') echo "  " . c('1;33', $r['group']) . "\n";
            $lastGroup = $r['group'];
        }
        $mark = $r['pass'] === true ? c('32', 'PASS') : ($r['pass'] === null ? c('33', 'SKIP') : c('31', 'FAIL'));
        printf("    %s  %s\n", $mark, $r['name']);
        if ($r['detail'] !== '' && $r['pass'] !== true) {
            echo '          ' . c('90', $r['detail']) . "\n";
        }
    }

    printf(
        "  %s %d passed, %d failed, %d skipped\n",
        c('1', '→'),
        $s->passed(),
        $s->failed(),
        $s->skipped()
    );
}

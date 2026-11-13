<?php
/** Recover from a session directory copied from another computer's PHP setup. */
declare(strict_types=1);
function ensure_session_storage(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || ini_get('session.save_handler') !== 'files') return;
    $configured = (string) session_save_path();
    $parts = explode(';', $configured);
    $directory = (string) end($parts);
    if ($directory === '') $directory = sys_get_temp_dir();
    if (is_dir($directory) && is_writable($directory)) return;

    // Use a private, installation-specific directory in PHP's temporary storage.
    $fallback = rtrim(sys_get_temp_dir(), '/\\') . '/coopvote-sessions-' . substr(hash('sha256', BASE_PATH), 0, 20);
    if (!is_dir($fallback) && !@mkdir($fallback, 0700, true) && !is_dir($fallback)) {
        throw new RuntimeException('SESSION_STORAGE_UNAVAILABLE');
    }
    if (!is_writable($fallback) || session_save_path($fallback) === false) {
        throw new RuntimeException('SESSION_STORAGE_UNAVAILABLE');
    }
}

/** Persist login state before any response is sent, including PHP write warnings. */
function persist_login_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $writeWarning = false;
    // PHP may return true from session_write_close() even when the files handler
    // warns that the write failed (for example, a full hosting account quota).
    set_error_handler(static function () use (&$writeWarning): bool {
        $writeWarning = true;
        return true;
    });
    try {
        $saved = session_write_close();
    } finally {
        restore_error_handler();
    }
    if ($saved && !$writeWarning) return;

    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'], 'domain' => $cookie['domain'],
            'secure' => $cookie['secure'], 'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);
    }
    throw new RuntimeException('SESSION_STORAGE_UNAVAILABLE');
}

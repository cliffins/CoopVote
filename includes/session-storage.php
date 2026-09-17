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

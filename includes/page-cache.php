<?php
declare(strict_types=1);
require_once __DIR__ . '/device-theme.php';
// Entry pages must revalidate their script versions on every navigation.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

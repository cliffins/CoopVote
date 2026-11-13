<?php
// PHP's test server does not read .htaccess; mirror protected HTML rewrites.
declare(strict_types=1);
if (getenv('COOPVOTE_ENV') !== 'test') {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(login|management)\.html$#D', $path, $match)) {
    require dirname(__DIR__, 2) . '/' . $match[1] . '.php';
    return true;
}
if (preg_match('#^/(admin|voter)/([a-z-]+)\.html$#D', $path, $match)) {
    $page = dirname(__DIR__, 2) . '/' . $match[1] . '/' . $match[2] . '.php';
    if (is_file($page)) {
        require $page;
        return true;
    }
}
return false;

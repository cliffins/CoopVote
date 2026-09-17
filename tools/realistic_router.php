<?php
/** Temporary loopback test router. The operator must configure its database and disable email. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__uat_health') {
    require __DIR__ . '/../config/config.php';
    header('Content-Type: application/json');
    echo json_encode(['database'=>DB_NAME,'mailEnabled'=>MAIL_ENABLED]);
    return true;
}
if (preg_match('#^/(database|config|includes|logs|tools|tests|vendor)(/|$)#i', $path)
    || preg_match('/\.(sql|ps1|json|xlsx|csv|ini|log|bak|md)$/i', $path)) {
    http_response_code(403); echo 'Forbidden'; return true;
}
return false;

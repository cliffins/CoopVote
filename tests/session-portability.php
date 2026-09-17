<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../includes/auth.php';
if (DB_NAME !== 'coopvote_test') exit(2);
$original = session_save_path();
$result = auth_attempt('t_admin', 'Test@1234');
$id = $_SESSION['user_id'] ?? null;
session_write_close();
$_SESSION = [];
auth_boot();
$persisted = $id !== null && ($_SESSION['user_id'] ?? null) === $id;
$fallback = session_save_path() !== $original;
session_destroy();
echo json_encode(['ok'=>$result['ok'],'persisted'=>$persisted,'fallback'=>$fallback]);

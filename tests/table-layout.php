<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('COOPVOTE_DB=coopvote_test');
putenv('COOPVOTE_MAIL=0');
require __DIR__.'/../includes/auth.php';
require __DIR__.'/lib/testdb.php';
require __DIR__.'/lib/http.php';
if (!TestServer::start(1,8097)) exit(1);
try { passthru('node tests/table-layout.cjs', $status); }
finally { TestServer::stop(); }
exit($status);

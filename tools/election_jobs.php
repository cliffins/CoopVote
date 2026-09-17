<?php
/** Schedule every minute on the host to capture reports after timed election closure. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../api/routes/voter.php';
require_once __DIR__ . '/../includes/election-reports.php';
election_reports_due();
echo "Scheduled election reports checked.\n";

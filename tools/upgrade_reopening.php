<?php
/** Additive upgrade only: no election is opened and no voting data is changed. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../includes/helpers.php';
db()->exec(file_get_contents(BASE_PATH . '/database/migration_07_election_reopening.sql'));
echo "Election reopening report fields installed. Existing voting data is unchanged.\n";

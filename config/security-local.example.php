<?php
/** Copy to security-local.php and use private paths outside the website. */
return [
    'backup_key_file' => '/private/coopvote/backup.key',
    'backup_directory' => '/private/coopvote-backups',
    'db_ssl_ca' => '/private/coopvote/database-ca.crt',
];

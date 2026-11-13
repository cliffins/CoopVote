<?php
/** Compatibility helper: all existing callers share the DB singleton. */
declare(strict_types=1);
require_once __DIR__ . '/DB.php';

function db(): PDO
{
    return DB::getConnection();
}

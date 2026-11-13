<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/includes/helpers.php';
require dirname(__DIR__, 2) . '/includes/installation.php';
$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
}
$p = db();
check((int) $p->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements'")->fetchColumn() === 0, 'retired announcements table absent');
check($p === DB::getConnection() && $p === db(), 'singleton identity');
check($p->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'exception mode');
check(!$p->getAttribute(PDO::ATTR_EMULATE_PREPARES), 'native prepared statements');
check($p->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC, 'associative fetches');
check($p->query('SELECT @@session.time_zone')->fetchColumn() === '+08:00', 'Manila timezone');
check($p->query('SELECT @@character_set_connection')->fetchColumn() === 'utf8mb4', 'UTF-8 connection');
$schema = installation_schema_issues($p, DB_NAME);
check(!$schema['missingTables'] && !$schema['missingColumns'], 'complete schema');
$base = ['environment' => 'production', 'db_host' => '127.0.0.1', 'db_name' => 'example', 'db_user' => 'example', 'db_password' => 'example'];
$none = static fn(string $key) => false;
$tcp = database_settings($base, $none);
check($tcp['tls_required'] && $tcp['encryption_required'], 'TCP policy stays strict');
$local = database_settings($base + ['db_socket' => '/run/mysqld/mysqld.sock', 'db_encryption_required' => false], $none);
check(!$local['tls_required'] && !$local['encryption_required'], 'explicit local policy');
foreach (
    [
        ['db_host' => '127.0.0.1;dbname=other'],
        ['db_name' => 'other;host=bad'],
        ['db_port' => 0],
        ['db_port' => 65536],
        ['db_user' => ''],
        ['db_password' => ''],
        ['db_socket' => 'relative.sock'],
        ['db_socket' => "/tmp/socket;host=bad"],
        ['db_encryption_required' => 'invalid'],
        ['db_encryption_required' => false],
        ['environment' => 'test', 'db_name' => 'production'],
    ] as $override
) {
    try {
        database_settings(array_replace($base, $override), $none);
        check(false, 'reject invalid settings');
    } catch (DatabaseConfigurationException) {
        $checks++;
    }
}
try {
    database_pdo_options($tcp);
    check(false, 'missing TLS is rejected');
} catch (DatabaseConfigurationException $e) {
    check($e->reason === 'DB_TLS_REQUIRED', 'missing TLS reason');
}
try {
    database_pdo_options(array_replace($local, ['ca' => '/missing/ca.pem']));
    check(false, 'socket/TLS conflict');
} catch (DatabaseConfigurationException $e) {
    check($e->reason === 'DB_CONFIG_INVALID', 'socket/TLS conflict reason');
}
$classified = installation_error(new DatabaseConfigurationException('DB_AUTH_FAILED', 'operator-only secret detail'));
check(!str_contains(json_encode($classified), 'secret') && $classified['code'] === 'DB_AUTH_FAILED', 'safe API errors');
if (DB_NAME === 'coopvote_test') {
    check((int)$p->query('SELECT COUNT(*) FROM votes')->fetchColumn() === 1, 'one vote despite repeat submission');
    check((int)$p->query('SELECT COUNT(*) FROM ballots WHERE has_voted=1')->fetchColumn() === 2, 'mixed and all-abstain ballots both count as participation');
    check((int)$p->query("SELECT COUNT(*) FROM ballots WHERE has_voted=1 AND receipt_code IS NOT NULL")->fetchColumn() === 2, 'both voters receive receipts');
    check((int)$p->query('SELECT COUNT(*) FROM votes WHERE candidate_id=2')->fetchColumn() === 0, 'abstentions never increase candidate totals');
    check((int)$p->query('SELECT COUNT(*) FROM vote_integrity')->fetchColumn() === 1, 'signed vote stored');
    check((int)$p->query('SELECT COUNT(*) FROM election_reports')->fetchColumn() >= 1, 'signed report stored');
    check((int)$p->query('SELECT COUNT(*) FROM member_profile_photo_chunks')->fetchColumn() === 1, 'photo chunks stored');
    try {
        db_transaction(static function (PDO $transaction): void {
            check($transaction === db(), 'transaction shares singleton');
            $transaction->exec("INSERT INTO settings(setting_key,setting_value) VALUES('rollback_probe','test')");
            throw new RuntimeException('rollback probe');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'rollback probe') throw $e;
    }
    check(!$p->inTransaction(), 'failed transaction closed');
    check(!$p->query("SELECT 1 FROM settings WHERE setting_key='rollback_probe'")->fetchColumn(), 'failed transaction rolled back');
}
echo "$checks/$checks database/configuration checks PASS\n";

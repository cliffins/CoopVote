<?php
/** Validate the empty deliverable and relational invariants in an owned scratch DB. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
putenv('COOPVOTE_MAIL=0');
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/sql-import.php';
$schema = file_get_contents(__DIR__.'/../database/schema_only.sql');
$checks = 0;
$check = static function(bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$check(!preg_match('/\b(?:INSERT|REPLACE|TRUNCATE|DROP)\b|\bCREATE\s+DATABASE\b|^\s*USE\s/im', $schema), 'DDL only');
$check(!str_contains($schema, '`election_controls`') && !str_contains($schema, '`password_resets`'), 'Retired tables absent');
$name = 'coopvote_schematest_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
if (!preg_match('/^coopvote_schematest_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $name) || $name === DB_NAME) exit(2);
$pdo = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$reject = static function(string $sql, string $label) use ($pdo, $check): void {
    try { $pdo->exec($sql); }
    catch (PDOException $e) {
        $check(in_array((int)($e->errorInfo[1] ?? 0), [1062,1451,1452,4025], true), $label.' expected a constraint error');
        return;
    }
    throw new RuntimeException($label.' was accepted');
};
$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $pdo->exec('USE `'.$name.'`');
    sql_import($pdo, $schema);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $check(count($tables) === 25, '25 current tables');
    foreach ($tables as $table) $check((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn() === 0, $table.' empty');
    $check((int)$pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn() === 1, 'FK checks restored');
    foreach (['votes','vote_integrity'] as $table) {
        $columns = $pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
        $check(!array_intersect($columns, ['user_id','member_id','ballot_id','receipt_code']), $table.' has no identity link');
    }
    $pdo->exec("INSERT INTO roles(role_id,role_name,slug) VALUES(1,'Voter','voter')");
    $pdo->exec("INSERT INTO users(user_id,username,email,password_hash,role_id) VALUES(1,'schema-test','schema@example.invalid','unusable-test-hash',1)");
    $pdo->exec("INSERT INTO members(member_id,user_id,full_name) VALUES(1,1,'Schema Test')");
    $pdo->exec("INSERT INTO elections(election_id,title,start_datetime,end_datetime,created_by) VALUES(1,'First','2030-01-01','2030-01-02',1),(2,'Second','2030-01-01','2030-01-02',1)");
    $pdo->exec("INSERT INTO positions(position_id,election_id,position_name) VALUES(1,1,'Chair'),(2,2,'Chair'),(3,1,'Secretary')");
    $reject('INSERT INTO candidates(member_id,position_id,election_id) VALUES(1,2,1)', 'Cross-election candidate');
    $pdo->exec('INSERT INTO candidates(candidate_id,member_id,position_id,election_id) VALUES(1,1,1,1),(2,1,2,2)');
    $check((int)$pdo->query('SELECT COUNT(*) FROM candidates WHERE member_id=1')->fetchColumn() === 2, 'Same member can run in different elections');
    $reject('INSERT INTO candidates(member_id,position_id,election_id) VALUES(1,3,1)', 'Duplicate election nomination');
    $reject("INSERT INTO votes(candidate_id,position_id,election_id,cast_date) VALUES(1,2,2,'2030-01-01')", 'Cross-election vote');
    $reject("INSERT INTO votes(candidate_id,position_id,election_id,cast_date) VALUES(1,3,1,'2030-01-01')", 'Wrong-position vote');
    $pdo->exec("INSERT INTO votes(vote_id,candidate_id,position_id,election_id,cast_date) VALUES(1,1,1,1,'2030-01-01')");
    $pdo->exec('INSERT INTO ballots(user_id,election_id,has_voted) VALUES(1,1,1)');
    $reject('INSERT INTO ballots(user_id,election_id,has_voted) VALUES(1,1,1)', 'Duplicate voter participation');
    $pdo->exec("INSERT INTO vote_integrity(vote_id,election_id,nonce,signature,origin) VALUES(1,1,REPEAT('a',32),REPEAT('b',64),'native')");
    $pdo->exec('DELETE FROM votes WHERE vote_id=1');
    $check((int)$pdo->query('SELECT COUNT(*) FROM vote_integrity WHERE vote_id=1')->fetchColumn() === 1, 'Deleted vote leaves integrity evidence');
    $reject("INSERT INTO security_alert_reviews(alert_key,reviewed_by) VALUES(REPEAT('a',64),999)", 'Missing reviewer');
    $reject('INSERT INTO governance_config(id,required_approvals) VALUES(2,1)', 'Second config key');
    $reject('INSERT INTO governance_config(id,required_approvals) VALUES(1,0)', 'Zero approvals');
    $reject("INSERT INTO election_approval_requests(election_id,fingerprint,required_approvals,requested_by) VALUES(1,REPEAT('a',64),0,1)", 'Zero request threshold');
    echo $checks.' schema checks passed.'.PHP_EOL;
} finally {
    $pdo->exec('DROP DATABASE `'.$name.'`');
}

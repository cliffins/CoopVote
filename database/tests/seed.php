<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
if (getenv('COOPVOTE_ENV') !== 'test') throw new RuntimeException('Test mode required.');
require $root . '/config/database.php';
if (DB_NAME !== 'coopvote_test' || !str_starts_with(DB_SETTINGS['socket'], '/tmp/coopvote-db-tests-') || basename(DB_SETTINGS['socket']) !== 'mysql.sock') {
    throw new RuntimeException('Refusing to reset anything except the disposable socket fixture.');
}
$p=new PDO('mysql:unix_socket='.DB_SETTINGS['socket'],'root','');
$p->exec('DROP DATABASE IF EXISTS coopvote_test');
$p->exec('CREATE DATABASE coopvote_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
require $root . '/includes/sql-import.php';
sql_import(db(),file_get_contents($root . '/database/schema_only.sql'));
db()->exec("INSERT INTO roles(role_id,role_name,slug) VALUES(1,'Administrator','admin'),(3,'Voter','voter'); INSERT INTO governance_config(id,required_approvals) VALUES(1,1)");
$s=db()->prepare("INSERT INTO users(user_id,username,email,password_hash,role_id,status,email_verified_at) VALUES(?,?,?,?,?,'active',NOW())");
foreach([[1,'testadmin',1],[2,'testvoter',3],[3,'testnominee',3]] as [$id,$name,$role]) {
    $s->execute([$id,$name,$name.'@example.test',password_hash('EndpointTest9!',PASSWORD_BCRYPT),$role]);
}
db()->exec("INSERT INTO members(member_id,user_id,member_no,full_name,date_of_birth,contact_no,address,membership_status) VALUES(1,2,'TEST-1','Test Voter','1990-01-01','09171234567','Test address','active'),(2,3,'TEST-2','Test Nominee','1990-01-01','09171234568','Test address','active')");
echo "Fixture ready\n";

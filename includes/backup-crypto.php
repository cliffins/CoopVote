<?php
/** Authenticated AES-256-GCM envelopes for offline SQL backups. */
declare(strict_types=1);

function backup_key(): string
{
    $path = getenv('COOPVOTE_BACKUP_KEY_FILE');
    if (!$path && is_file(__DIR__ . '/../config/security-local.php')) {
        $local = require __DIR__ . '/../config/security-local.php';
        $path = $local['backup_key_file'] ?? '';
    }
    $hex = $path && is_readable($path) ? trim((string) file_get_contents($path)) : '';
    if (!preg_match('/^[a-f0-9]{64}$/iD', $hex)) {
        throw new RuntimeException('A separate 32-byte backup key is required. Set COOPVOTE_BACKUP_KEY_FILE.');
    }
    return hex2bin($hex);
}

function backup_encrypt(string $plaintext, string $key): string
{
    if (strlen($key) !== 32) throw new InvalidArgumentException('Expected a 256-bit key.');
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'CoopVote-backup-v1', 16);
    if ($ciphertext === false) throw new RuntimeException('Backup encryption failed.');
    return "CVBACKUP1\n" . $nonce . $tag . $ciphertext;
}

function backup_decrypt(string $envelope, string $key): string
{
    if (strlen($key) !== 32 || strlen($envelope) < 38 || substr($envelope, 0, 10) !== "CVBACKUP1\n") {
        throw new RuntimeException('Invalid encrypted backup.');
    }
    $plaintext = openssl_decrypt(substr($envelope, 38), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($envelope, 10, 12), substr($envelope, 22, 16), 'CoopVote-backup-v1');
    if ($plaintext === false) throw new RuntimeException('Backup authentication failed: wrong key or modified file.');
    return $plaintext;
}

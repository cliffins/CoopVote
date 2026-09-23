<?php
declare(strict_types=1);

function branding_defaults(): array
{
    return ['text' => 'CoopVote', 'logo' => null, 'coopName' => 'Santiago City Multi-Purpose Cooperative'];
}

/** The caller owns the transaction so branding and related settings save together. */
function branding_store(PDO $pdo, array $data): void
{
    $save = $pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
    $save->execute(['site_branding', json_encode(['text' => $data['text'], 'logo' => $data['logo']], JSON_THROW_ON_ERROR)]);
    $save->execute(['coop_name', $data['coopName']]);
}

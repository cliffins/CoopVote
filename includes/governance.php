<?php
/** Shared account preferences, validation errors and administrator lock order. */
/** Shared account preferences, validation errors and administrator lock order. */
declare(strict_types=1);
require_once __DIR__ . '/validation.php';
const GOVERNANCE_THEMES = ['navy', 'light', 'forest', 'ocean', 'plum', 'slate'];
function admin_accounts_lock(PDO $pdo): void
{
    $pdo->query("SELECT role_id FROM roles WHERE slug='admin' FOR UPDATE")->fetchColumn();
}
final class GovernanceError extends RuntimeException
{
    public function __construct(string $message, public int $status = 422) { parent::__construct($message); }
}
function governance_fail(string $message, int $status = 422): never { throw new GovernanceError($message, $status); }

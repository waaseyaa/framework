<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Consent;

use DateTimeImmutable;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Records and queries user consent decisions.
 *
 * Consent is scoped to (account_id, client_id, scope_set_hash).
 * The scope_set_hash is SHA-256 of the sorted, space-joined granted scopes —
 * a different scope set requires a new consent decision.
 *
 * @api
 */
final class ConsentRepository
{
    private const TABLE = 'oidc_user_consent';

    private bool $tableEnsured = false;

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    /**
     * Check whether the account has previously consented to this client+scopes.
     *
     * @param list<string> $scopes
     */
    public function hasConsent(string $accountId, string $clientId, array $scopes): bool
    {
        $this->ensureTable();

        $hash = $this->scopeSetHash($scopes);

        foreach (
            $this->database->select(self::TABLE)
                ->condition('account_id', $accountId)
                ->condition('client_id', $clientId)
                ->condition('scope_set_hash', $hash)
                ->execute() as $_row
        ) {
            return true;
        }

        return false;
    }

    /**
     * Record that the account has consented to this client+scopes.
     *
     * @param list<string> $scopes
     */
    public function record(string $accountId, string $clientId, array $scopes): void
    {
        $this->ensureTable();

        $hash = $this->scopeSetHash($scopes);
        $now = new DateTimeImmutable()->getTimestamp();

        // INSERT OR IGNORE to handle concurrent requests gracefully
        $this->database->query(
            'INSERT OR IGNORE INTO ' . self::TABLE . ' (account_id, client_id, scope_set_hash, granted_at) VALUES (?, ?, ?, ?)',
            [$accountId, $clientId, $hash, $now],
        );
    }

    /**
     * @param list<string> $scopes
     */
    private function scopeSetHash(array $scopes): string
    {
        $sorted = $scopes;
        sort($sorted);

        return hash('sha256', implode(' ', $sorted));
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->database->query(<<<'SQL'
                CREATE TABLE IF NOT EXISTS oidc_user_consent (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    account_id VARCHAR(255) NOT NULL,
                    client_id VARCHAR(255) NOT NULL,
                    scope_set_hash CHAR(64) NOT NULL,
                    granted_at INTEGER NOT NULL
                )
            SQL);

        $this->tableEnsured = true;
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Consent;

use DateTimeImmutable;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;

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

    private bool $schemaVerified = false;

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
        $this->assertSchemaAvailable();

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
        $this->assertSchemaAvailable();

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

    private function assertSchemaAvailable(): void
    {
        if ($this->schemaVerified) {
            return;
        }

        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            ['id', 'account_id', 'client_id', 'scope_set_hash', 'granted_at'],
            'waaseyaa/oidc:2026_05_25_000004_oidc_user_consent_schema',
        );

        $this->schemaVerified = true;
    }
}

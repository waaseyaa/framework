<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Oidc\Exception\DuplicateClientIdException;

/**
 * Enforces registry-identity uniqueness on oidc_client.client_id (#2766).
 *
 * `client_id` is the OIDC client registry's stable public identifier; every
 * authorize/token/refresh/revoke path resolves a client by this value before
 * authentication runs. Prior to this migration the column carried no
 * database constraint, so a race or operator error could seed two rows with
 * the same client_id and the pre-auth lookup would silently pick whichever
 * row the query planner returned first (`OidcClientLookup::findByClientId()`
 * now refuses that state instead — see #2766).
 *
 * Idempotent: safe to re-run once the index exists (both directly, as
 * OidcClientSchemaMigrationTest exercises, and via the ledger).
 *
 * Fails closed rather than silently choosing a winner: if historical
 * duplicate client_id values already exist, this migration throws
 * DuplicateClientIdException and creates no index. It never deletes or
 * merges a row — resolving the reported duplicates is an explicit operator
 * decision, then `bin/waaseyaa migrate` is rerun to reapply this migration.
 * This entity declares no `#[StorageUniqueKey]`; the constraint is owned
 * entirely by this migration, so `schema:sync` cannot materialize it —
 * `bin/waaseyaa migrate` is the only sanctioned recovery command.
 *
 * An empty string is the pre-column-add default (`ADD COLUMN client_id
 * VARCHAR(255) NOT NULL DEFAULT ''` in the base migration) and carries no
 * registry identity — OidcClientLookup::findByClientId('') already returns
 * null unconditionally. The unique index is therefore partial (SQLite
 * `WHERE client_id <> ''`), so pre-existing or admin-left-blank rows never
 * block materialization or collide with each other.
 */
return new class extends Migration {
    private const string INDEX_NAME = 'oidc_client_client_id_unique';

    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('oidc_client') || !$schema->hasColumn('oidc_client', 'client_id')) {
            // Base migration has not run yet on this connection; nothing to enforce yet.
            return;
        }

        $connection = $schema->getConnection();
        $indexes = $connection->createSchemaManager()->listTableIndexes('oidc_client');
        if (isset($indexes[strtolower(self::INDEX_NAME)]) || isset($indexes[self::INDEX_NAME])) {
            return;
        }

        /** @var list<string> $duplicates */
        $duplicates = $connection->fetchFirstColumn(
            "SELECT client_id FROM oidc_client WHERE client_id <> '' "
            . 'GROUP BY client_id HAVING COUNT(*) > 1 ORDER BY client_id',
        );
        if ($duplicates !== []) {
            throw new DuplicateClientIdException($duplicates);
        }

        $connection->executeStatement(
            'CREATE UNIQUE INDEX ' . self::INDEX_NAME . " ON oidc_client (client_id) WHERE client_id <> ''",
        );
    }

    public function down(SchemaBuilder $schema): void
    {
        // Additive SQLite schema: dropping the index is version-dependent; leave no-op.
    }
};

<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Testing;

use Waaseyaa\Database\DBALDatabase;

/** Explicit test-fixture schema; production schema is DB-02 migration-owned. */
final class EntityMutationAuthoritySchema
{
    public static function ensure(DBALDatabase $database): void
    {
        if ($database->schema()->tableExists('waaseyaa_entity_mutation_authority')) {
            return;
        }
        $database->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_entity_mutation_authority (
                storage_authority VARCHAR(191) NOT NULL,
                tenant_id VARCHAR(191) NOT NULL,
                entity_type VARCHAR(191) NOT NULL,
                entity_id VARCHAR(191) NOT NULL,
                aggregate_version INTEGER NOT NULL,
                mutation_tag VARCHAR(64) NOT NULL,
                lifecycle_state VARCHAR(16) NOT NULL,
                PRIMARY KEY (storage_authority, tenant_id, entity_type, entity_id)
            )
            SQL);
    }
}

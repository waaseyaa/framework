<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\ClientRegistry;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Exception\UnstorableFieldException;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * #2766 — keeps the storage-profile matrix honest for {@see OidcClient}.
 *
 * `OidcClient` does not declare a `storageBackend` on its
 * `#[ContentEntityType]` attribute, so the framework default — `sql-blob`,
 * where `redirect_uris`, `scopes`, and `grant_types` live in the `_data` JSON
 * blob — is its one and only supported layout. That default is deliberate,
 * not an oversight: those three fields are plain PHP `array` values with no
 * declared column-encoding contract, and `sql-column` materialises a real
 * column per field with no blob to fall back to.
 *
 * This test pins that boundary from the other side, mirroring
 * `packages/entity-storage/tests/Integration/SqlColumn/DataStoredFieldPersistenceTest`'s
 * "unsupported values fail loudly rather than vanishing" contract: a
 * deliberate `sql-column` composition of this exact entity shape must refuse
 * the array fields with {@see UnstorableFieldException} rather than silently
 * dropping them (the #2165 failure mode) or truncating/serializing them into
 * something that cannot reload. If `OidcClient` is ever given a real
 * `sql-column` encoding contract for these fields, this test is the one to
 * update alongside that change.
 */
#[CoversNothing]
final class OidcClientSqlColumnBoundaryTest extends TestCase
{
    #[Test]
    public function aSqlColumnCompositionRefusesTheRedirectUrisArrayFieldLoudly(): void
    {
        $repository = $this->sqlColumnRepositoryForOidcClient();

        $entity = $repository->create([
            'client_id' => 'column-client',
            'name' => 'Column client',
            'redirect_uris' => ['https://column.test/cb'],
        ]);

        $this->expectException(UnstorableFieldException::class);
        $this->expectExceptionMessageMatches('/redirect_uris/');

        $repository->save($entity, validate: false);
    }

    /**
     * Builds a real EntitySchemaSync + SqlStorageDriver + EntityRepository
     * composition for OidcClient, EXACTLY as production wiring would, except
     * with primaryStorageBackend forced to sql-column. OidcClient's own
     * #[ContentEntityType] attribute declares no storageBackend (defaults to
     * sql-blob) — this override exists only to prove what the framework does
     * if that default were ever changed, not to exercise anything reachable
     * through OidcServiceProvider today.
     */
    private function sqlColumnRepositoryForOidcClient(): \Waaseyaa\EntityStorage\EntityRepository
    {
        $blobType = EntityType::fromClass(OidcClient::class);

        $columnType = new EntityType(
            id: $blobType->id(),
            label: $blobType->getLabel(),
            class: $blobType->getClass(),
            keys: $blobType->getKeys(),
            description: $blobType->getDescription(),
            primaryStorageBackend: PrimaryStorageBackend::SQL_COLUMN,
            _fieldDefinitions: $blobType->getFieldDefinitions(),
        );

        $database = DBALDatabase::createSqlite();
        $fieldRegistry = new FieldDefinitionRegistry();
        $fieldRegistry->registerCoreFields($columnType->id(), $columnType->getFieldDefinitions());

        new EntitySchemaSync($database)->syncAll([$columnType]);

        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $columnType,
            new SqlStorageDriver(new SingleConnectionResolver($database), fieldRegistry: $fieldRegistry),
            new EventDispatcher(),
            database: $database,
            fieldRegistry: $fieldRegistry,
        );
    }
}

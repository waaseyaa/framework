<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\ClientRegistry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Oidc\Exception\AmbiguousClientIdException;

#[CoversClass(OidcClientLookup::class)]
final class OidcClientLookupTest extends TestCase
{
    private EntityRepository $repository;
    private OidcClientLookup $lookup;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();

        $entityType = new EntityType(
            id: 'oidc_client',
            label: 'OIDC Client',
            class: OidcClient::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $database);
        $schemaHandler->ensureTable();
        $schemaHandler->addFieldColumns([
            'client_id' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'is_confidential' => ['type' => 'int', 'not null' => true, 'default' => 0],
            'client_secret_hash' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
        ]);

        $dispatcher = new EventDispatcher();
        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($database)),
            $dispatcher,
            database: $database,
        );

        $this->lookup = new OidcClientLookup($this->repository);
    }

    public function testReturnsNullWhenClientIdNotFound(): void
    {
        $this->assertNull($this->lookup->findByClientId('unknown-client'));
    }

    public function testReturnsClientWhenClientIdMatches(): void
    {
        $client = $this->repository->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
        ]);
        $this->repository->save($client);

        $found = $this->lookup->findByClientId('minoo-web');

        $this->assertInstanceOf(OidcClient::class, $found);
        $this->assertSame('minoo-web', $found->getClientId());
        $registration = new OidcClientSystemReader()->registration($found);
        $this->assertSame('Minoo', $registration->name);
        $this->assertSame(['https://minoo.test/callback'], $registration->redirectUris);
    }

    public function testDoesNotMatchPartialClientId(): void
    {
        $client = $this->repository->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
        ]);
        $this->repository->save($client);

        $this->assertNull($this->lookup->findByClientId('minoo'));
        $this->assertNull($this->lookup->findByClientId('minoo-web-extra'));
    }

    public function testRefusesAmbiguousMultiMatchInsteadOfPickingAnArbitraryRow(): void
    {
        // #2766: client_id is a database-enforced unique registry identity
        // (see the 2026_09_06_000009 migration). This test schema is built
        // without that constraint on purpose, to prove the lookup itself
        // fails closed as the defense-in-depth backstop — it must never
        // silently pick an arbitrary row the way $ids[0] once did.
        $first = $this->repository->create([
            'client_id' => 'dup',
            'name' => 'First',
            'redirect_uris' => ['https://one.test/cb'],
        ]);
        $this->repository->save($first);

        $second = $this->repository->create([
            'client_id' => 'dup',
            'name' => 'Second',
            'redirect_uris' => ['https://two.test/cb'],
        ]);
        $this->repository->save($second);

        try {
            $this->lookup->findByClientId('dup');
            $this->fail('Expected AmbiguousClientIdException.');
        } catch (AmbiguousClientIdException $exception) {
            $this->assertSame('oidc_client_id_ambiguous', $exception->errorCode);
            $this->assertSame('dup', $exception->clientId);
            $this->assertCount(2, $exception->matchingIds);

            // #2766 independent review: OidcClient declares no
            // #[StorageUniqueKey], so the physical constraint is owned
            // entirely by the 2026_09_06_000009 migration, not by the
            // declarative schema-sync mechanism — `schema:sync` cannot
            // materialize this index. The operator recovery instruction
            // must name the migration runner, never schema:sync.
            $this->assertStringContainsString(
                'bin/waaseyaa migrate',
                $exception->getMessage(),
                'the recovery instruction must name the migration runner that actually owns this constraint',
            );
            $this->assertStringNotContainsString(
                'schema:sync',
                $exception->getMessage(),
                'schema:sync cannot materialize a migration-only unique index and must never be suggested as recovery',
            );
        }
    }

    public function testEmptyClientIdReturnsNull(): void
    {
        $client = $this->repository->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
        ]);
        $this->repository->save($client);

        $this->assertNull($this->lookup->findByClientId(''));
    }
}

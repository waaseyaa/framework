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

    public function testReturnsFirstMatchWhenMultipleExist(): void
    {
        // client_id is expected to be unique, but the lookup must behave
        // deterministically if a duplicate ever slips past uniqueness checks.
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

        $found = $this->lookup->findByClientId('dup');

        $this->assertInstanceOf(OidcClient::class, $found);
        $this->assertSame('First', new OidcClientSystemReader()->registration($found)->name);
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

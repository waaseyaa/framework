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
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Oidc\ClientRegistry\OidcClientLookup;
use Waaseyaa\Oidc\Entity\OidcClient;

#[CoversClass(OidcClientLookup::class)]
final class OidcClientLookupTest extends TestCase
{
    private SqlEntityStorage $storage;
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
        $this->storage = new SqlEntityStorage(
            $entityType,
            $database,
            $dispatcher,
        );

        $repository = new EntityRepository(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($database)),
            $dispatcher,
            database: $database,
        );

        $this->lookup = new OidcClientLookup($repository);
    }

    public function testReturnsNullWhenClientIdNotFound(): void
    {
        $this->assertNull($this->lookup->findByClientId('unknown-client'));
    }

    public function testReturnsClientWhenClientIdMatches(): void
    {
        $client = $this->storage->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
        ]);
        $this->storage->save($client);

        $found = $this->lookup->findByClientId('minoo-web');

        $this->assertInstanceOf(OidcClient::class, $found);
        $this->assertSame('minoo-web', $found->getClientId());
        $this->assertSame('Minoo', $found->getName());
        $this->assertSame(['https://minoo.test/callback'], $found->getRedirectUris());
    }

    public function testDoesNotMatchPartialClientId(): void
    {
        $client = $this->storage->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
        ]);
        $this->storage->save($client);

        $this->assertNull($this->lookup->findByClientId('minoo'));
        $this->assertNull($this->lookup->findByClientId('minoo-web-extra'));
    }

    public function testReturnsFirstMatchWhenMultipleExist(): void
    {
        // client_id is expected to be unique, but the lookup must behave
        // deterministically if a duplicate ever slips past uniqueness checks.
        $first = $this->storage->create([
            'client_id' => 'dup',
            'name' => 'First',
            'redirect_uris' => ['https://one.test/cb'],
        ]);
        $this->storage->save($first);

        $second = $this->storage->create([
            'client_id' => 'dup',
            'name' => 'Second',
            'redirect_uris' => ['https://two.test/cb'],
        ]);
        $this->storage->save($second);

        $found = $this->lookup->findByClientId('dup');

        $this->assertInstanceOf(OidcClient::class, $found);
        $this->assertSame('First', $found->getName());
    }

    public function testEmptyClientIdReturnsNull(): void
    {
        $client = $this->storage->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
        ]);
        $this->storage->save($client);

        $this->assertNull($this->lookup->findByClientId(''));
    }
}

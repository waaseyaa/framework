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
use Waaseyaa\Oidc\ClientRegistry\OidcClientSeeder;
use Waaseyaa\Oidc\Entity\OidcClient;

#[CoversClass(OidcClientSeeder::class)]
final class OidcClientSeederTest extends TestCase
{
    private SqlEntityStorage $storage;
    private OidcClientSeeder $seeder;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();

        $entityType = new EntityType(
            id: 'oidc_client',
            label: 'OIDC Client',
            class: OidcClient::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        );

        (new SqlSchemaHandler($entityType, $database))->ensureTable();
        (new SqlSchemaHandler($entityType, $database))->addFieldColumns([
            'client_id' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'is_confidential' => ['type' => 'int', 'not null' => true, 'default' => 0],
            'client_secret_hash' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
        ]);

        $dispatcher = new EventDispatcher();
        $this->storage = new SqlEntityStorage($entityType, $database, $dispatcher);
        $repository = new EntityRepository(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($database)),
            $dispatcher,
            database: $database,
        );
        $this->seeder = new OidcClientSeeder($repository);
    }

    public function testSeedEmptyConfigIsNoOp(): void
    {
        $this->seeder->seed([]);
        $this->assertCount(0, $this->storage->getQuery()->accessCheck(false)->execute());
    }

    public function testSeedCreatesNewClient(): void
    {
        $this->seeder->seed([
            'minoo-web' => [
                'name' => 'Minoo',
                'redirect_uris' => ['https://minoo.test/callback'],
            ],
        ]);

        $ids = $this->storage->getQuery()->accessCheck(false)->condition('client_id', 'minoo-web')->execute();
        $this->assertCount(1, $ids);

        $client = $this->storage->load($ids[0]);
        $this->assertSame('minoo-web', $client->getClientId());
        $this->assertSame('Minoo', $client->getName());
        $this->assertSame(['https://minoo.test/callback'], $client->getRedirectUris());
        $this->assertSame(['openid'], $client->getScopes(), 'default scopes applied');
        $this->assertSame(['authorization_code'], $client->getGrantTypes(), 'default grant types applied');
    }

    public function testSeedAppliesAllOptionalFields(): void
    {
        $this->seeder->seed([
            'biindigen' => [
                'name' => 'Biindigen',
                'redirect_uris' => ['https://biindigen.test/cb'],
                'scopes' => ['openid', 'profile', 'email'],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'is_confidential' => true,
                'client_secret_hash' => 'hashed',
            ],
        ]);

        $ids = $this->storage->getQuery()->accessCheck(false)->condition('client_id', 'biindigen')->execute();
        $client = $this->storage->load($ids[0]);
        $this->assertSame(['openid', 'profile', 'email'], $client->getScopes());
        $this->assertSame(['authorization_code', 'refresh_token'], $client->getGrantTypes());
        $this->assertTrue($client->isConfidential());
        $this->assertSame('hashed', $client->getClientSecretHash());
    }

    public function testSeedUpdatesExistingClient(): void
    {
        // First seed.
        $this->seeder->seed([
            'minoo-web' => [
                'name' => 'Minoo',
                'redirect_uris' => ['https://minoo.test/callback'],
            ],
        ]);
        $ids = $this->storage->getQuery()->accessCheck(false)->condition('client_id', 'minoo-web')->execute();
        $originalId = $ids[0];
        $originalUuid = $this->storage->load($originalId)->uuid();

        // Re-seed with updated fields.
        $this->seeder->seed([
            'minoo-web' => [
                'name' => 'Minoo (renamed)',
                'redirect_uris' => [
                    'https://minoo.test/callback',
                    'https://minoo.test/new-callback',
                ],
                'scopes' => ['openid', 'profile'],
            ],
        ]);

        // Still only one row.
        $this->assertCount(1, $this->storage->getQuery()->accessCheck(false)->condition('client_id', 'minoo-web')->execute());

        // Updated in place: same id, same uuid, new fields.
        $reloaded = $this->storage->load($originalId);
        $this->assertSame($originalId, $reloaded->id());
        $this->assertSame($originalUuid, $reloaded->uuid());
        $this->assertSame('Minoo (renamed)', $reloaded->getName());
        $this->assertSame(
            ['https://minoo.test/callback', 'https://minoo.test/new-callback'],
            $reloaded->getRedirectUris(),
        );
        $this->assertSame(['openid', 'profile'], $reloaded->getScopes());
    }

    public function testSeedIsIdempotent(): void
    {
        $config = [
            'minoo-web' => [
                'name' => 'Minoo',
                'redirect_uris' => ['https://minoo.test/callback'],
            ],
        ];

        $this->seeder->seed($config);
        $this->seeder->seed($config);
        $this->seeder->seed($config);

        $this->assertCount(1, $this->storage->getQuery()->accessCheck(false)->execute());
    }

    public function testSeedDoesNotDeleteAdminCreatedClients(): void
    {
        // Admin creates a client outside of config.
        $admin = $this->storage->create([
            'client_id' => 'admin-added',
            'name' => 'Admin-created client',
            'redirect_uris' => ['https://example.test/cb'],
        ]);
        $this->storage->save($admin);

        // Seed does not mention admin-added.
        $this->seeder->seed([
            'minoo-web' => [
                'name' => 'Minoo',
                'redirect_uris' => ['https://minoo.test/callback'],
            ],
        ]);

        // Admin client survives.
        $this->assertCount(
            1,
            $this->storage->getQuery()->accessCheck(false)->condition('client_id', 'admin-added')->execute(),
            'admin-added client must not be deleted by seeder',
        );
    }

    public function testSeedDoesNotDeleteClientsRemovedFromConfig(): void
    {
        // Seed two clients.
        $this->seeder->seed([
            'minoo-web' => ['name' => 'Minoo', 'redirect_uris' => ['https://minoo.test/cb']],
            'biindigen' => ['name' => 'Biindigen', 'redirect_uris' => ['https://biindigen.test/cb']],
        ]);
        $this->assertCount(2, $this->storage->getQuery()->accessCheck(false)->execute());

        // Re-seed with only one.
        $this->seeder->seed([
            'minoo-web' => ['name' => 'Minoo', 'redirect_uris' => ['https://minoo.test/cb']],
        ]);

        // biindigen was NOT deleted — config removal is non-destructive.
        $this->assertCount(2, $this->storage->getQuery()->accessCheck(false)->execute());
    }

    public function testSeedThrowsOnMissingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name');

        $this->seeder->seed([
            'minoo-web' => ['redirect_uris' => ['https://minoo.test/cb']],
        ]);
    }

    public function testSeedThrowsOnMissingRedirectUris(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redirect_uris');

        $this->seeder->seed([
            'minoo-web' => ['name' => 'Minoo'],
        ]);
    }

    public function testSeedThrowsOnEmptyRedirectUris(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->seeder->seed([
            'minoo-web' => ['name' => 'Minoo', 'redirect_uris' => []],
        ]);
    }

    public function testSeedThrowsOnNonStringClientId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->seeder->seed([
            0 => ['name' => 'x', 'redirect_uris' => ['https://x.test/cb']],
        ]);
    }
}

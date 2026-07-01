<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\EntityStorageEngineParity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * C-22 WP1 — behavior-identity harness: bundle subtable rows.
 *
 * Both `SqlEntityStorage::save()` and `EntityRepository::doSave()` route
 * bundle-scoped field persistence through the SAME
 * {@see \Waaseyaa\EntityStorage\Bundle\BundleSubtableGateway} instance shape
 * (partition → upsert), from two independent call sites
 * (`SqlEntityStorage.php:354-368`, `EntityRepository.php:797-816`). This test
 * saves the SAME bundle-field values through both engines — to separate rows,
 * against one shared registry/schema — and diffs the raw subtable columns.
 *
 * Fixture mirrors `SqlEntityStorageBundleFieldsTest` (`group`/`group_type`,
 * `business` bundle with `email`/`phone` fields).
 *
 * This suite must stay green through C-22 WP2–WP4.
 */
#[CoversNothing]
final class BundleSubtableParityTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $groupType;
    private FieldDefinitionRegistry $registry;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');

        $this->groupType = new EntityType(
            id: 'parity_group',
            label: 'Parity Group',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'gid',
                'uuid' => 'uuid',
                'bundle' => 'type',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
            bundleEntityType: 'parity_group_type',
        );

        $this->registry = new FieldDefinitionRegistry();
        $this->registry->registerBundleFields('parity_group', 'business', [
            new FieldDefinition(name: 'email', type: 'string', targetEntityTypeId: 'parity_group', targetBundle: 'business'),
            new FieldDefinition(name: 'phone', type: 'string', targetEntityTypeId: 'parity_group', targetBundle: 'business'),
        ]);

        new SqlSchemaHandler(
            $this->groupType,
            $this->database,
            $this->registry,
            static fn(): iterable => ['business'],
        )->ensureTable();
    }

    #[Test]
    public function bundleSubtableRowsAreByteIdenticalAcrossEngines(): void
    {
        $shared = [
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'hi@acme.example',
            'phone' => '555-0100',
        ];

        $dispatcher = new EventDispatcher();
        $storage = new SqlEntityStorage($this->groupType, $this->database, $dispatcher, $this->registry);
        $storageEntity = $storage->create(['uuid' => 'uuid-storage'] + $shared);
        $storage->save($storageEntity);

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'gid');
        $repository = new EntityRepository($this->groupType, $driver, $dispatcher, database: $this->database, fieldRegistry: $this->registry);
        $repositoryEntity = $storage->create(['uuid' => 'uuid-repository'] + $shared);
        $repository->save($repositoryEntity, validate: false);

        $storageSub = $this->subtableRow((int) $storageEntity->id());
        $repositorySub = $this->subtableRow((int) $repositoryEntity->id());

        self::assertSame($storageSub['email'], $repositorySub['email']);
        self::assertSame($storageSub['phone'], $repositorySub['phone']);
        self::assertSame('hi@acme.example', $storageSub['email']);
        self::assertSame('555-0100', $storageSub['phone']);

        // Base row `_data` blob must NOT contain the bundle-partitioned values
        // on either engine.
        $storageBase = $this->baseRow((int) $storageEntity->id());
        $repositoryBase = $this->baseRow((int) $repositoryEntity->id());
        self::assertArrayNotHasKey('email', \json_decode((string) $storageBase['_data'], true) ?: []);
        self::assertArrayNotHasKey('email', \json_decode((string) $repositoryBase['_data'], true) ?: []);
    }

    /**
     * @return array<string, mixed>
     */
    private function subtableRow(int $id): array
    {
        $rows = \iterator_to_array($this->database->query(
            'SELECT email, phone FROM "parity_group__business" WHERE gid = ?',
            [$id],
        ));
        self::assertCount(1, $rows);

        return (array) $rows[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRow(int $id): array
    {
        $rows = \iterator_to_array($this->database->query(
            'SELECT _data FROM "parity_group" WHERE gid = ?',
            [$id],
        ));
        self::assertCount(1, $rows);

        return (array) $rows[0];
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase12;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Verifies {@see EntityTypeManager::getRepository()} wiring against SQLite (framework #1128).
 */
#[CoversNothing]
final class EntityTypeManagerRepositoryIntegrationTest extends TestCase
{
    #[Test]
    public function get_repository_persists_and_loads_via_sqlite(): void
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $manager = new EntityTypeManager(
            $dispatcher,
            // C-22 WP4: legacy SqlEntityStorage engine is deleted; only getRepository()
            // is exercised by this test, so no storage factory is wired.
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher): EntityRepositoryInterface {
                $schemaHandler = new SqlSchemaHandler($definition, $database);
                $schemaHandler->ensureTable();
                if ($definition->isRevisionable()) {
                    $schemaHandler->ensureRevisionTable();
                }
                if ($definition->isTranslatable()) {
                    $schemaHandler->ensureTranslationTable();
                }

                $keys = $definition->getKeys();
                $idKey = $keys['id'] ?? 'id';
                $resolver = new SingleConnectionResolver($database);
                $driver = new SqlStorageDriver($resolver, $idKey);
                $revisionDriver = $definition->isRevisionable()
                    ? new RevisionableStorageDriver($resolver, $definition)
                    : null;

                return new EntityRepository(
                    $definition,
                    $driver,
                    $dispatcher,
                    $revisionDriver,
                    $database,
                );
            },
        );

        $type = new EntityType(
            id: 'test_entity',
            label: 'Repo integration',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
        $manager->registerEntityType($type);

        $repository = $manager->getRepository('test_entity');

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Hello', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew();

        $this->assertSame(EntityConstants::SAVED_NEW, $repository->save($entity));

        $loaded = $repository->find('1');
        $this->assertInstanceOf(TestStorageEntity::class, $loaded);
        $this->assertSame('Hello', $loaded->get('label'));
    }
}

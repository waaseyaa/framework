<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Fixtures;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * Builds a fully-wired {@see EntityTypeManager} against a fresh in-memory
 * SQLite database — repository factory, field registry, and per-bundle
 * subtable auto-materialization — WITHOUT pulling in a higher-layer content
 * package (`node`) as a migration package test dependency.
 *
 * Mirrors `Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory`'s shape
 * closely enough that a passing {@see \Waaseyaa\Migration\ContentModel\ContentModelRegistrar}
 * test here is representative of the real kernel-built manager an
 * `import:*` command resolves in production — this is the "db:init
 * equivalent" fresh-schema starting point the G-026 (#1940) failure-mode
 * regression test needs.
 *
 * @internal Test fixture only.
 */
final class ContentModelTestKit
{
    public function __construct(
        public readonly DBALDatabase $database,
        public readonly EntityTypeManager $typeManager,
        public readonly FieldDefinitionRegistry $fieldRegistry,
    ) {
    }

    public static function build(): self
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $fieldRegistry = new FieldDefinitionRegistry();

        $typeManager = new EntityTypeManager(
            eventDispatcher: $dispatcher,
            repositoryFactory: function (string $entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher, $fieldRegistry): EntityRepositoryInterface {
                $schemaHandler = new SqlSchemaHandler($definition, $database, $fieldRegistry);
                $schemaHandler->ensureTable();

                $keys = $definition->getKeys();
                $idKey = $keys['id'] ?? 'id';

                $resolver = new SingleConnectionResolver($database);
                $driver = new SqlStorageDriver($resolver, $idKey, null, $fieldRegistry);

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    entityType: $definition,
                    driver: $driver,
                    eventDispatcher: $dispatcher,
                    database: $database,
                    fieldRegistry: $fieldRegistry,
                );
            },
            fieldRegistry: $fieldRegistry,
            bundleSubtableExistsProbe: static fn(string $entityTypeId, string $bundle): bool
                => $database->schema()->tableExists(SqlSchemaHandler::resolveSubtableName($entityTypeId, $bundle)),
            bundleSubtableMaterializer: function (EntityTypeInterface $type) use ($database, $fieldRegistry): void {
                new SqlSchemaHandler($type, $database, $fieldRegistry)->ensureTable();
            },
        );

        $typeManager->registerEntityType(self::pageContentType());
        $typeManager->registerEntityType(self::contentTypeConfigType());

        return new self($database, $typeManager, $fieldRegistry);
    }

    /**
     * The "node"-analog content entity type: bundle-scoped, declares a real
     * `bundleEntityType` so {@see \Waaseyaa\Migration\ContentModel\ContentModelRegistrar}
     * exercises both `ensureBundleConfigEntity()` and `declareFields()`.
     */
    public static function pageContentType(): EntityType
    {
        return new EntityType(
            id: 'migration_test_page',
            label: 'Migration Test Page',
            class: MigrationTestPage::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'page_type'],
            bundleEntityType: 'migration_test_content_type',
        );
    }

    /** The "node_type"-analog bundle config entity type. */
    public static function contentTypeConfigType(): EntityType
    {
        return new EntityType(
            id: 'migration_test_content_type',
            label: 'Migration Test Content Type',
            class: MigrationTestContentTypeConfig::class,
            keys: ['id' => 'id', 'label' => 'label'],
        );
    }
}

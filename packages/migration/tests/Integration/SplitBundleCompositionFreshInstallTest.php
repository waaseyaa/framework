<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\Process\PartitionedLookupProcessor;
use Waaseyaa\Migration\Plugin\Source\FilteredSource;
use Waaseyaa\Migration\Plugin\SourceRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;

/**
 * Fresh-install reproduction for #1981.
 *
 * The database begins empty, installs the migration package through its real
 * package migration files, then runs fixed-bundle definitions through the
 * real runner, access gate, entity destination, and id-map. Alpha.260 cannot
 * express the source filtering, mixed-partition term lookup, or media
 * partition lookup below without application-defined adapter classes.
 */
#[CoversNothing]
final class SplitBundleCompositionFreshInstallTest extends TestCase
{
    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;

    protected function setUp(): void
    {
        EntityType::clearFromClassCache();
        $this->db = DBALDatabase::createSqlite();
        $connection = $this->db->getConnection();
        $schema = new SchemaBuilder($connection);

        foreach ([
            '2026_05_13_000001_create_migration_id_map.php',
            '2026_05_13_000002_create_migration_run_state.php',
        ] as $file) {
            $migration = require dirname(__DIR__, 2) . '/migrations/' . $file;
            $migration->up($schema);
        }

        $connection->executeStatement(
            'CREATE TABLE "node" ('
            . '"nid" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, "title" TEXT, "type" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );

        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: Node::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type', 'revision' => 'revision_id'],
            _fieldDefinitions: [
                'term_refs' => ['type' => 'json', 'read' => FieldReadLevel::Public],
            ],
        );
        $this->typeManager = new EntityTypeManager(new EventDispatcher());
        $this->typeManager->registerEntityType($entityType);
        $this->dispatcher = new EventDispatcher();
        $this->repository = new EntityRepository(
            entityType: $entityType,
            driver: new SqlStorageDriver(new SingleConnectionResolver($this->db), 'nid'),
            eventDispatcher: $this->dispatcher,
        );
        $this->idMap = new MigrationIdMap($this->db);
        $this->gate = new EntityAccessGate(new EntityAccessHandler([new NodeAccessPolicy()]));
    }

    protected function tearDown(): void
    {
        EntityType::clearFromClassCache();
    }

    #[Test]
    public function split_bundle_sources_and_id_maps_compose_without_site_adapters(): void
    {
        $termSource = new InMemorySource('legacy_terms', [
            new SourceRecord('legacy_term', ['id' => '1', 'partition' => 'category', 'title' => 'News']),
            new SourceRecord('legacy_term', ['id' => '2', 'partition' => 'tag', 'title' => 'Community']),
        ], sourceType: 'legacy_term');
        $mediaSource = new InMemorySource('legacy_media', [
            new SourceRecord('legacy_media', ['id' => '10', 'partition' => 'image', 'title' => 'Header']),
            new SourceRecord('legacy_media', ['id' => '11', 'partition' => 'document', 'title' => 'Agenda']),
        ], sourceType: 'legacy_media');

        $definitions = [
            $this->splitDefinition('terms_category', $termSource, 'category'),
            $this->splitDefinition('terms_tag', $termSource, 'tag'),
            $this->splitDefinition('media_image', $mediaSource, 'image'),
            $this->splitDefinition('media_document', $mediaSource, 'document'),
        ];

        $contentSource = new InMemorySource('legacy_content', [
            new SourceRecord('legacy_content', [
                'id' => '100',
                'title' => 'Mixed references',
                'terms' => [
                    ['partition' => 'category', 'id' => '1'],
                    ['partition' => 'tag', 'id' => '2'],
                ],
            ]),
        ], sourceType: 'legacy_content');
        $definitions[] = new MigrationDefinition(
            id: 'content_article',
            source: $contentSource,
            process: [
                'title' => 'title',
                'term_refs' => ['terms', new PartitionedLookupProcessor(
                    sourceField: 'terms',
                    migrationFor: static fn (mixed $item): ?string => is_array($item)
                        ? match ($item['partition'] ?? null) {
                            'category' => 'terms_category',
                            'tag' => 'terms_tag',
                            default => null,
                        }
                        : null,
                    sourceIdFor: static fn (mixed $item): ?SourceId => is_array($item) && isset($item['id'])
                        ? new SourceId('legacy_term', ['id' => (string) $item['id']])
                        : null,
                    resultFor: static fn (WriteResult $result): string => $result->destinationUuid,
                    onMiss: PartitionedLookupProcessor::ON_MISS_FAIL,
                )],
            ],
            destination: $this->destination('content_article', 'article'),
            dependencies: ['terms_category', 'terms_tag'],
            bundle: 'article',
        );

        $runner = $this->runner($definitions);
        foreach (['terms_category', 'terms_tag', 'media_image', 'media_document', 'content_article'] as $id) {
            $report = $runner->run($id, new RunOptions());
            self::assertSame(1, $report->imported, $id);
            self::assertSame(0, $report->failed, $id);
        }

        $content = $this->repository->findBy(['title' => 'Mixed references']);
        self::assertCount(1, $content);
        $termRefs = $content[0]->get('term_refs');
        self::assertIsArray($termRefs);
        self::assertCount(2, $termRefs);
        self::assertNotSame($termRefs[0], $termRefs[1]);

        foreach (['10' => 'image', '11' => 'document'] as $sourceId => $bundle) {
            $result = $this->idMap->lookupDestinationAcross(
                ['media_image', 'media_document'],
                new SourceId('legacy_media', ['id' => (string) $sourceId]),
            );
            self::assertInstanceOf(WriteResult::class, $result);
            $entity = $this->repository->findBy(['uuid' => $result->destinationUuid]);
            self::assertCount(1, $entity);
            self::assertSame($bundle, $entity[0]->get('type'));
        }

        self::assertCount(1, $this->repository->findBy(['type' => 'category']));
        self::assertCount(1, $this->repository->findBy(['type' => 'tag']));
        self::assertCount(1, $this->repository->findBy(['type' => 'image']));
        self::assertCount(1, $this->repository->findBy(['type' => 'document']));
    }

    private function splitDefinition(
        string $migrationId,
        InMemorySource $source,
        string $bundle,
    ): MigrationDefinition {
        return new MigrationDefinition(
            id: $migrationId,
            source: new FilteredSource(
                source: $source,
                accept: static fn (SourceRecord $record): bool => $record->field('partition') === $bundle,
                pluginId: $migrationId,
            ),
            process: ['title' => 'title'],
            destination: $this->destination($migrationId, $bundle),
            bundle: $bundle,
        );
    }

    private function destination(string $migrationId, string $bundle): EntityDestination
    {
        return new EntityDestination(
            destinationEntityTypeId: 'node',
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: $migrationId,
            account: new MigrationSystemAccount(["create {$bundle} content"]),
        );
    }

    /** @param list<MigrationDefinition> $definitions */
    private function runner(array $definitions): MigrationRunner
    {
        $provider = new class($definitions) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $definitions */
            public function __construct(private readonly array $definitions) {}

            public function migrations(): iterable
            {
                yield from $this->definitions;
            }
        };
        $registry = new MigrationRegistry([$provider]);
        $registry->boot();

        return new MigrationRunner($registry, new ProcessChainExecutor(), $this->idMap);
    }
}

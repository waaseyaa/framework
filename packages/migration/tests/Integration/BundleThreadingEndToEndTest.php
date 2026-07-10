<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\PluginFixtures\InMemorySource;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\ProcessChainExecutor;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidget;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidgetType;

/**
 * G-015 (issue #1940) — end-to-end proof that a bundle declared on
 * {@see MigrationDefinition} survives the full pipeline
 * (`MigrationDefinition` → `MigrationRunner::processOne()` →
 * `DestinationRecord::$bundle` → {@see EntityDestination}) and lands on the
 * saved entity's bundle field.
 *
 * Unlike {@see EntityDestinationTest}, which constructs `DestinationRecord`
 * by hand (and so never exercised the runner's threading — the actual
 * G-015 gap), this test drives a real {@see MigrationRunner::run()} so the
 * plumbing between the definition and the record is genuinely exercised.
 *
 * Reuses {@see MigrationTestWidgetType::nonRevisionable()}, which declares
 * a `bundle` entity key (`widget_type`) precisely so this suite does not
 * need a second widget fixture — the key lands in the `_data` JSON blob
 * like any other undeclared column, so no schema change was needed either.
 */
#[CoversNothing]
final class BundleThreadingEndToEndTest extends TestCase
{
    private const string MIGRATION_ID = 'bundle_threading_demo';
    private const string ENTITY_TYPE_ID = 'migration_test_widget';

    private DBALDatabase $db;
    private EntityTypeManager $typeManager;
    private EntityRepository $repository;
    private MigrationIdMap $idMap;
    private EventDispatcher $dispatcher;
    private EntityAccessGate $gate;
    private AccountInterface $systemAccount;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $conn = $this->db->getConnection();

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS "migration_test_widget" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"title" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );

        $conn->executeStatement(MigrationIdMapSchema::createTableSql());
        foreach (MigrationIdMapSchema::createIndexSqls() as $sql) {
            $conn->executeStatement($sql);
        }

        $entityType = MigrationTestWidgetType::nonRevisionable();
        $this->typeManager = new EntityTypeManager(new EventDispatcher());
        $this->typeManager->registerEntityType($entityType);

        $this->dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver, 'id');
        $this->repository = new EntityRepository(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $this->dispatcher,
        );

        $this->idMap = new MigrationIdMap($this->db);
        $this->systemAccount = new MigrationSystemAccount();
        $this->gate = new EntityAccessGate(new EntityAccessHandler([new AllowAllPolicy(self::ENTITY_TYPE_ID)]));
    }

    #[Test]
    public function bundle_declared_on_the_definition_lands_on_the_saved_entity(): void
    {
        $destination = new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $this->systemAccount,
        );

        $source = new InMemorySource(
            id: 'in_memory',
            records: [new \Waaseyaa\Migration\Plugin\SourceRecord('in_memory', [
                'id' => 'one',
                'title' => 'Hello bundle',
            ])],
        );

        $definition = new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: $source,
            process: ['title' => 'title'],
            destination: $destination,
            bundle: 'article',
        );

        $runner = $this->buildRunner($definition);
        $report = $runner->run(self::MIGRATION_ID, new RunOptions());

        self::assertFalse($report->aborted);
        self::assertSame(1, $report->imported);

        $stmt = $this->db->getConnection()->executeQuery(
            'SELECT "_data" FROM "migration_test_widget"',
        );
        $row = $stmt->fetchAssociative();
        self::assertIsArray($row);
        /** @var string $rawData */
        $rawData = $row['_data'];
        $data = \json_decode($rawData, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('article', $data['widget_type'] ?? null);

        // Also confirmed via the entity repository / bundle entity key.
        $entities = $this->repository->findBy(['title' => 'Hello bundle']);
        self::assertCount(1, $entities);
        self::assertInstanceOf(MigrationTestWidget::class, $entities[0]);
        self::assertSame('article', $entities[0]->get('widget_type'));
    }

    #[Test]
    public function no_bundle_declared_leaves_the_bundle_field_unset(): void
    {
        $destination = new EntityDestination(
            destinationEntityTypeId: self::ENTITY_TYPE_ID,
            entityTypeManager: $this->typeManager,
            entityRepository: $this->repository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->dispatcher,
            migrationId: self::MIGRATION_ID,
            account: $this->systemAccount,
        );

        $source = new InMemorySource(
            id: 'in_memory',
            records: [new \Waaseyaa\Migration\Plugin\SourceRecord('in_memory', [
                'id' => 'one',
                'title' => 'No bundle here',
            ])],
        );

        $definition = new MigrationDefinition(
            id: self::MIGRATION_ID,
            source: $source,
            process: ['title' => 'title'],
            destination: $destination,
        );

        $runner = $this->buildRunner($definition);
        $report = $runner->run(self::MIGRATION_ID, new RunOptions());

        self::assertFalse($report->aborted);
        self::assertSame(1, $report->imported);

        $entities = $this->repository->findBy(['title' => 'No bundle here']);
        self::assertCount(1, $entities);
        self::assertInstanceOf(MigrationTestWidget::class, $entities[0]);
        self::assertNull($entities[0]->get('widget_type'));
    }

    private function buildRunner(MigrationDefinition $definition): MigrationRunner
    {
        $provider = new class([$definition]) implements HasMigrationsInterface {
            /** @param list<MigrationDefinition> $defs */
            public function __construct(private readonly array $defs)
            {
            }

            public function migrations(): iterable
            {
                yield from $this->defs;
            }
        };

        $registry = new MigrationRegistry([$provider]);
        $registry->boot();

        return new MigrationRunner(
            registry: $registry,
            chain: new ProcessChainExecutor(),
            idMap: $this->idMap,
        );
    }
}

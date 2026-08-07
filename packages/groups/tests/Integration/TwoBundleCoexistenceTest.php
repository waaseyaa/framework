<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\BundleAmbiguousFieldException;
use Waaseyaa\EntityStorage\SqlEntityQuery;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Groups\GroupsServiceProvider;

/**
 * Two test-only bundles ('alpha' and 'beta') with partially overlapping field
 * sets registered against the `group` entity type produced by
 * GroupsServiceProvider.
 *
 * Verifies subtable materialization, save/load round-trip, query-time JOIN
 * routing, and the ambiguity policy defined in
 * docs/specs/bundle-scoped-fields.md §Query.
 */
#[CoversNothing]
final class TwoBundleCoexistenceTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeInterface $groupType;
    private FieldDefinitionRegistry $registry;
    private EventDispatcher $dispatcher;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $this->groupType = $this->resolveGroupType();
        $this->registry = new FieldDefinitionRegistry();
        $this->dispatcher = new EventDispatcher();
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_groups_twobundle_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectRoot);
    }

    #[Test]
    public function bothSubtablesAreMaterializedWhenBothBundlesHaveFields(): void
    {
        $this->registerAlphaFields();
        $this->registerBetaFields();

        $this->ensureSchema(['alpha', 'beta']);

        $schema = $this->database->schema();
        self::assertTrue($schema->tableExists('group'));
        self::assertTrue($schema->tableExists('group__alpha'));
        self::assertTrue($schema->tableExists('group__beta'));
    }

    #[Test]
    public function savingEntitiesInEachBundleRoutesFieldsToCorrectSubtables(): void
    {
        $this->registerAlphaFields();
        $this->registerBetaFields();
        $this->ensureSchema(['alpha', 'beta']);

        $repository = $this->repository();

        $alpha = $repository->create([
            'uuid' => 'uuid-alpha',
            'type' => 'alpha',
            'name' => 'Alpha One',
            'langcode' => 'en',
            'alpha_code' => 'A-1',
            'shared_tag' => 'alpha-tag',
        ]);
        $repository->save($alpha);

        $beta = $repository->create([
            'uuid' => 'uuid-beta',
            'type' => 'beta',
            'name' => 'Beta One',
            'langcode' => 'en',
            'beta_code' => 'B-1',
            'shared_tag' => 'beta-tag',
        ]);
        $repository->save($beta);

        $alphaRow = iterator_to_array(
            $this->database->query('SELECT alpha_code, shared_tag FROM group__alpha', []),
            false,
        )[0] ?? null;
        self::assertNotNull($alphaRow);
        self::assertSame('A-1', $alphaRow['alpha_code']);
        self::assertSame('alpha-tag', $alphaRow['shared_tag']);

        $betaRow = iterator_to_array(
            $this->database->query('SELECT beta_code, shared_tag FROM group__beta', []),
            false,
        )[0] ?? null;
        self::assertNotNull($betaRow);
        self::assertSame('B-1', $betaRow['beta_code']);
        self::assertSame('beta-tag', $betaRow['shared_tag']);
    }

    #[Test]
    public function roundTripLoadMergesSubtableValuesBack(): void
    {
        $this->registerAlphaFields();
        $this->ensureSchema(['alpha']);

        $repository = $this->repository();

        $entity = $repository->create([
            'uuid' => 'uuid-alpha-rt',
            'type' => 'alpha',
            'name' => 'RT',
            'langcode' => 'en',
            'alpha_code' => 'RT-CODE',
            'shared_tag' => 'RT-TAG',
        ]);
        $repository->save($entity);

        $loaded = $repository->find((string) $entity->id());
        self::assertNotNull($loaded);
        self::assertSame('RT-CODE', $loaded->get('alpha_code'));
        self::assertSame('RT-TAG', $loaded->get('shared_tag'));
    }

    #[Test]
    public function queryBundleScopedFieldWithExplicitBundleNarrowsViaInnerJoin(): void
    {
        $this->registerAlphaFields();
        $this->registerBetaFields();
        $this->ensureSchema(['alpha', 'beta']);

        $repository = $this->repository();
        $repository->save($repository->create([
            'uuid' => 'uuid-a',
            'type' => 'alpha',
            'name' => 'A',
            'langcode' => 'en',
            'alpha_code' => 'A-42',
        ]));
        $repository->save($repository->create([
            'uuid' => 'uuid-b',
            'type' => 'beta',
            'name' => 'B',
            'langcode' => 'en',
            'beta_code' => 'B-42',
        ]));

        // accessCheck(false): this test exercises bundle-scoped JOIN routing
        // for subtable storage; GroupApiSurfaceTest covers the package policy.
        $ids = (new SqlEntityQuery($this->groupType, $this->database, null, $this->registry))
            ->accessCheck(false)
            ->condition('type', 'alpha')
            ->condition('alpha_code', 'A-42')
            ->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function queryAmbiguousFieldWithoutBundleConstraintThrows(): void
    {
        $this->registerAlphaFields();
        $this->registerBetaFields();
        $this->ensureSchema(['alpha', 'beta']);

        // 'shared_tag' exists in both alpha and beta; no bundle constraint and
        // no sibling bundle-specific field — ambiguity must surface as an
        // exception, never a silent bundle choice.
        $this->expectException(BundleAmbiguousFieldException::class);

        // accessCheck(false): this test asserts query-time ambiguity detection,
        // while GroupApiSurfaceTest covers the package policy.
        (new SqlEntityQuery($this->groupType, $this->database, null, $this->registry))
            ->accessCheck(false)
            ->condition('shared_tag', 'anything')
            ->execute();
    }

    #[Test]
    public function healthCheckerIsCleanWithTwoSubtablesPresent(): void
    {
        $this->registerAlphaFields();
        $this->registerBetaFields();
        $this->ensureSchema(['alpha', 'beta']);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([$this->groupType->id() => $this->groupType]);

        $report = new BootDiagnosticReport(
            registeredTypes: [$this->groupType->id() => $this->groupType],
            disabledTypeIds: [],
            schemaCompatibility: [],
        );

        $checker = new HealthChecker(
            bootReport: $report,
            database: $this->database,
            entityTypeManager: $manager,
            projectRoot: $this->projectRoot,
            fieldRegistry: $this->registry,
        );

        foreach ($checker->checkSchemaDrift() as $result) {
            self::assertNotSame('fail', $result->status, sprintf(
                'Drift reported with both subtables present: %s — %s',
                $result->name,
                $result->message,
            ));
        }
    }

    // --- Helpers ---

    private function resolveGroupType(): EntityTypeInterface
    {
        $provider = new GroupsServiceProvider();
        $provider->register();
        foreach ($provider->getEntityTypes() as $t) {
            if ($t->id() === 'group') {
                return $t;
            }
        }
        self::fail('Groups provider did not register "group" entity type.');
    }

    private function registerAlphaFields(): void
    {
        $this->registry->registerBundleFields('group', 'alpha', [
            new FieldDefinition(
                name: 'alpha_code',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'alpha',
                read: FieldReadLevel::Public,
            ),
            new FieldDefinition(
                name: 'shared_tag',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'alpha',
                read: FieldReadLevel::Public,
            ),
        ]);
    }

    private function registerBetaFields(): void
    {
        $this->registry->registerBundleFields('group', 'beta', [
            new FieldDefinition(
                name: 'beta_code',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'beta',
                read: FieldReadLevel::Public,
            ),
            new FieldDefinition(
                name: 'shared_tag',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'beta',
                read: FieldReadLevel::Public,
            ),
        ]);
    }

    /**
     * @param list<string> $bundles
     */
    private function ensureSchema(array $bundles): void
    {
        (new SqlSchemaHandler(
            $this->groupType,
            $this->database,
            $this->registry,
            static fn (): iterable => $bundles,
        ))->ensureTable();
    }

    private function repository(): EntityRepository
    {
        // Group::class declares 'gid' (not 'id') as its id key — the driver
        // must be told, or read()/write() key off the wrong column.
        $idKey = $this->groupType->getKeys()['id'] ?? 'id';

        return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->groupType,
            new SqlStorageDriver(new SingleConnectionResolver($this->database), $idKey),
            $this->dispatcher,
            database: $this->database,
            fieldRegistry: $this->registry,
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

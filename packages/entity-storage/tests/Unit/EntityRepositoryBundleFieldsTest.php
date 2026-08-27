<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Bundle\BundleSubtableGateway;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;

/**
 * Bundle-scoped storage tests (C-22 WP4: ported from the retired
 * SqlEntityStorageBundleFieldsTest — SqlEntityStorage is deleted; the shared
 * {@see \Waaseyaa\EntityStorage\Bundle\BundleSubtableGateway} is now
 * exercised exclusively through EntityRepository, the sole persistence engine).
 *
 * Covers the §Resolution normalization boundary and the bundle-scoped subtable
 * round trip documented in docs/specs/bundle-scoped-storage.md.
 */
#[CoversClass(EntityRepository::class)]
#[CoversClass(BundleSubtableGateway::class)]
final class EntityRepositoryBundleFieldsTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $groupType;
    private FieldDefinitionRegistry $registry;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');

        $this->groupType = new EntityType(
            id: 'group',
            label: 'Group',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'gid',
                'uuid' => 'uuid',
                'bundle' => 'type',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
            bundleEntityType: 'group_type',
        );

        $this->registry = new FieldDefinitionRegistry();
        $this->dispatcher = new EventDispatcher();
    }

    /**
     * Test 1: two-bundle round trip — disjoint bundle fields land in their
     * subtables on save and merge back into the entity on load.
     */
    #[Test]
    public function roundTripSavesAndLoadsDisjointBundleFields(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $repository = $this->makeRepository();

        $biz = $repository->create([
            'uuid' => 'uuid-biz',
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'hi@acme.example',
            'phone' => '555-0100',
        ]);
        $repository->save($biz, validate: false);
        $bizId = $biz->id();
        self::assertNotNull($bizId);

        $org = $repository->create([
            'uuid' => 'uuid-org',
            'type' => 'organization',
            'label' => 'OpenOrg',
            'langcode' => 'en',
            'website' => 'https://openorg.example',
            'org_code' => 'OPEN-1',
        ]);
        $repository->save($org, validate: false);
        $orgId = $org->id();
        self::assertNotNull($orgId);

        $loadedBiz = $repository->find((string) $bizId);
        self::assertNotNull($loadedBiz);
        self::assertSame('business', $loadedBiz->get('type'));
        self::assertSame('hi@acme.example', $loadedBiz->get('email'));
        self::assertSame('555-0100', $loadedBiz->get('phone'));
        self::assertFalse($loadedBiz->hasField('website'));

        $loadedOrg = $repository->find((string) $orgId);
        self::assertNotNull($loadedOrg);
        self::assertSame('organization', $loadedOrg->get('type'));
        self::assertSame('https://openorg.example', $loadedOrg->get('website'));
        self::assertSame('OPEN-1', $loadedOrg->get('org_code'));
        self::assertFalse($loadedOrg->hasField('email'));
    }

    /**
     * Test 2: findMany merges per-bundle subtable rows across mixed bundles
     * using one IN-query per bundle, not one lookup per entity.
     */
    #[Test]
    public function findManyBatchMergesPerBundleSubtables(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $repository = $this->makeRepository();

        $ids = [];
        foreach ([
            ['uuid-biz-1', 'business', 'Acme', ['email' => 'a@a.example', 'phone' => '1']],
            ['uuid-biz-2', 'business', 'Globex', ['email' => 'b@b.example', 'phone' => '2']],
            ['uuid-org-1', 'organization', 'OpenOrg', ['website' => 'https://o1.example', 'org_code' => 'O-1']],
            ['uuid-org-2', 'organization', 'WikiOrg', ['website' => 'https://o2.example', 'org_code' => 'O-2']],
        ] as [$uuid, $bundle, $label, $extras]) {
            $entity = $repository->create(\array_merge([
                'uuid' => $uuid,
                'type' => $bundle,
                'label' => $label,
                'langcode' => 'en',
            ], $extras));
            $repository->save($entity, validate: false);
            $ids[] = (string) $entity->id();
        }

        $loaded = $repository->findMany($ids);

        self::assertCount(4, $loaded);
        $byId = [];
        foreach ($loaded as $entity) {
            $byId[(string) $entity->id()] = $entity;
        }
        self::assertSame('a@a.example', $byId[$ids[0]]->get('email'));
        self::assertSame('2', $byId[$ids[1]]->get('phone'));
        self::assertSame('https://o1.example', $byId[$ids[2]]->get('website'));
        self::assertSame('O-2', $byId[$ids[3]]->get('org_code'));
    }

    /**
     * Test 3: core fields that are not base-table columns fall through to
     * the _data JSON blob on save and merge back on load. Bundle partitioning
     * must not short-circuit splitForWrite's existing fallback.
     */
    #[Test]
    public function coreFieldsFallBackToDataBlobWhenNotSchemaColumns(): void
    {
        $this->registerBusinessFields();
        $this->ensureSchema(['business']);
        $repository = $this->makeRepository();

        $entity = $repository->create([
            'uuid' => 'uuid-x',
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'hi@acme.example',
            'description' => 'No schema column for me',
            'tags' => ['foo', 'bar'],
        ]);
        $repository->save($entity, validate: false);

        $loaded = $repository->find((string) $entity->id());
        self::assertNotNull($loaded);
        self::assertSame('hi@acme.example', $loaded->get('email'));
        self::assertSame('No schema column for me', $loaded->get('description'));
        self::assertSame(['foo', 'bar'], $loaded->get('tags'));
    }

    /**
     * Test 4: a failing subtable write rolls the base-row insert back and
     * suppresses POST_SAVE. No row leaks into either table.
     */
    #[Test]
    public function failedSubtableWriteRollsBackBaseInsertAndSuppressesPostSave(): void
    {
        $this->registerBusinessFields();
        $this->ensureSchema(['business']);
        $repository = $this->makeRepository();

        $postSaveCount = 0;
        $this->dispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            static function (EntityEvent $_event) use (&$postSaveCount): void {
                ++$postSaveCount;
            },
        );

        // Make the bundle gateway believe the subtable exists (poison its cache),
        // then drop the real subtable so the upsert hits "no such table" inside the
        // transaction, exercising the rollback path.
        $gatewayMethod = new \ReflectionMethod(EntityRepository::class, 'bundleGateway');
        $gateway = $gatewayMethod->invoke($repository);
        self::assertNotNull($gateway);
        $existsProp = new \ReflectionProperty($gateway::class, 'existsCache');
        $existsProp->setValue($gateway, ['business' => true]);
        $this->database->getConnection()->executeStatement('DROP TABLE "group__business"');

        $entity = $repository->create([
            'uuid' => 'uuid-fail',
            'type' => 'business',
            'label' => 'Doomed',
            'langcode' => 'en',
            'email' => 'doomed@acme.example',
        ]);

        $caught = false;
        try {
            $repository->save($entity, validate: false);
        } catch (\Throwable $_e) {
            $caught = true;
        }
        self::assertTrue($caught, 'save must surface the subtable failure');
        self::assertSame(0, $postSaveCount, 'POST_SAVE must not fire on rollback');

        $rows = \iterator_to_array(
            $this->database->query('SELECT COUNT(*) AS c FROM "group"', []),
        );
        self::assertSame(0, (int) ((array) $rows[0])['c'], 'base row must be rolled back');
    }

    /**
     * Test 5: when bundle-scoped fields are present but the bundle subtable is
     * missing at save time, the write continues on the base row, the column-bound
     * bundle value is folded into the base `_data` blob (NEVER a silent drop), a
     * loud warning is emitted, and the value is recovered on load.
     */
    #[Test]
    public function saveLogsWarningAndFallsBackToDataWhenBundleSubtableIsMissing(): void
    {
        $this->registerBusinessFields();
        $this->ensureSchema(['business']);

        $messages = [];
        $logger = new class ($messages) implements LoggerInterface {
            use LoggerTrait;

            /** @var list<string> */
            private array $messages;

            /**
             * @param list<string> $messages
             */
            public function __construct(array &$messages)
            {
                $this->messages = &$messages;
            }

            public function log(\Waaseyaa\Foundation\Log\LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                if ($level === \Waaseyaa\Foundation\Log\LogLevel::WARNING) {
                    $this->messages[] = (string) $message;
                }
            }
        };
        $repository = $this->makeRepository($logger);

        $this->database->getConnection()->executeStatement('DROP TABLE "group__business"');

        $entity = $repository->create([
            'uuid' => 'uuid-missing-subtable',
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'hi@acme.example',
        ]);

        $repository->save($entity, validate: false);

        self::assertCount(1, $messages);
        self::assertStringContainsString('[MISSING_BUNDLE_SUBTABLE]', $messages[0]);
        self::assertStringContainsString('entity type "group" bundle "business"', $messages[0]);
        self::assertStringContainsString('"group__business"', $messages[0]);
        self::assertStringContainsString('_data', $messages[0], 'the fallback path is named in the warning');

        // Never a silent drop: the bundle value is folded into the base `_data`
        // blob and recovered on load.
        $loaded = $repository->find((string) $entity->id());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->hasField('email'), 'the value is recovered from the _data fallback');
        self::assertSame('hi@acme.example', $loaded->get('email'));
    }

    /**
     * Test 6: attempting to save a field registered against a different
     * bundle throws — the partitioner refuses to write silently-corrupt data.
     */
    #[Test]
    public function saveRejectsFieldsBelongingToOtherBundles(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $repository = $this->makeRepository();

        $entity = $repository->create([
            'uuid' => 'uuid-mix',
            'type' => 'business',
            'label' => 'Misrouted',
            'langcode' => 'en',
            'email' => 'ok@acme.example',
            'org_code' => 'LEAK',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('belongs to bundle "organization"');

        $repository->save($entity, validate: false);
    }

    /**
     * Test 7: loading an entity whose bundle has zero registered fields
     * skips the subtable lookup entirely — the merge path is a no-op and
     * no "no such table" error surfaces.
     */
    #[Test]
    public function loadSkipsSubtableLookupForEmptyBundles(): void
    {
        // Only 'business' has registered fields; 'organization' has none.
        $this->registerBusinessFields();
        $this->ensureSchema(['business', 'organization']);
        $repository = $this->makeRepository();

        $org = $repository->create([
            'uuid' => 'uuid-empty',
            'type' => 'organization',
            'label' => 'Bare',
            'langcode' => 'en',
        ]);
        $repository->save($org, validate: false);
        $orgId = $org->id();

        self::assertFalse(
            $this->database->schema()->tableExists('group__organization'),
            'empty bundle must not have a subtable',
        );

        $loaded = $repository->find((string) $orgId);
        self::assertNotNull($loaded);
        self::assertSame('organization', $loaded->get('type'));
        self::assertSame('Bare', $loaded->label());
    }

    /**
     * Test 8: entity types without bundleEntityType (the v0.1 legacy shape)
     * continue to behave as before — partitionBundleValues short-circuits,
     * no transaction is opened for the subtable, and no subtable query runs
     * on load.
     */
    #[Test]
    public function singleBundleEntityTypeRegressesToLegacyPath(): void
    {
        $singleBundle = new EntityType(
            id: 'thing',
            label: 'Thing',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        (new SqlSchemaHandler($singleBundle, $this->database))->ensureTable();

        $resolver = new SingleConnectionResolver($this->database);
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $singleBundle,
            new SqlStorageDriver($resolver),
            $this->dispatcher,
            database: $this->database,
            fieldRegistry: $this->registry,
        );

        $entity = $repository->create([
            'uuid' => 'uuid-thing',
            'label' => 'Solo',
            'langcode' => 'en',
        ]);
        $repository->save($entity, validate: false);

        $loaded = $repository->find((string) $entity->id());
        self::assertNotNull($loaded);
        self::assertSame('Solo', $loaded->label());
    }

    private function registerBusinessFields(): void
    {
        $this->registry->registerBundleFields('group', 'business', [
            new FieldDefinition(
                name: 'email',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'business',
            ),
            new FieldDefinition(
                name: 'phone',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'business',
            ),
        ]);
    }

    private function registerOrganizationFields(): void
    {
        $this->registry->registerBundleFields('group', 'organization', [
            new FieldDefinition(
                name: 'website',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'organization',
            ),
            new FieldDefinition(
                name: 'org_code',
                type: 'string',
                targetEntityTypeId: 'group',
                targetBundle: 'organization',
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
            static fn(): iterable => $bundles,
        ))->ensureTable();
    }

    private function makeRepository(?LoggerInterface $logger = null): EntityRepository
    {
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'gid');

        return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->groupType,
            $driver,
            $this->dispatcher,
            database: $this->database,
            fieldRegistry: $this->registry,
            logger: $logger,
        );
    }
}

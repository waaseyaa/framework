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
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;

/**
 * Commit-4 tests: activate the per-bundle save/load path in SqlEntityStorage.
 *
 * Covers the §Resolution normalization boundary and the bundle-scoped subtable
 * round trip documented in docs/specs/bundle-scoped-storage.md.
 */
#[CoversClass(SqlEntityStorage::class)]
final class SqlEntityStorageBundleFieldsTest extends TestCase
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
        $storage = $this->makeStorage();

        $biz = $storage->create([
            'uuid' => 'uuid-biz',
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'hi@acme.example',
            'phone' => '555-0100',
        ]);
        $storage->save($biz);
        $bizId = $biz->id();
        self::assertIsInt($bizId);

        $org = $storage->create([
            'uuid' => 'uuid-org',
            'type' => 'organization',
            'label' => 'OpenOrg',
            'langcode' => 'en',
            'website' => 'https://openorg.example',
            'org_code' => 'OPEN-1',
        ]);
        $storage->save($org);
        $orgId = $org->id();
        self::assertIsInt($orgId);

        $loadedBiz = $storage->load($bizId);
        self::assertNotNull($loadedBiz);
        self::assertSame('business', $loadedBiz->get('type'));
        self::assertSame('hi@acme.example', $loadedBiz->get('email'));
        self::assertSame('555-0100', $loadedBiz->get('phone'));
        self::assertFalse($loadedBiz->hasField('website'));

        $loadedOrg = $storage->load($orgId);
        self::assertNotNull($loadedOrg);
        self::assertSame('organization', $loadedOrg->get('type'));
        self::assertSame('https://openorg.example', $loadedOrg->get('website'));
        self::assertSame('OPEN-1', $loadedOrg->get('org_code'));
        self::assertFalse($loadedOrg->hasField('email'));
    }

    /**
     * Test 2: loadMultiple merges per-bundle subtable rows across mixed bundles
     * using one IN-query per bundle, not one lookup per entity.
     */
    #[Test]
    public function loadMultipleBatchMergesPerBundleSubtables(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $storage = $this->makeStorage();

        $ids = [];
        foreach ([
            ['uuid-biz-1', 'business', 'Acme', ['email' => 'a@a.example', 'phone' => '1']],
            ['uuid-biz-2', 'business', 'Globex', ['email' => 'b@b.example', 'phone' => '2']],
            ['uuid-org-1', 'organization', 'OpenOrg', ['website' => 'https://o1.example', 'org_code' => 'O-1']],
            ['uuid-org-2', 'organization', 'WikiOrg', ['website' => 'https://o2.example', 'org_code' => 'O-2']],
        ] as [$uuid, $bundle, $label, $extras]) {
            $entity = $storage->create(\array_merge([
                'uuid' => $uuid,
                'type' => $bundle,
                'label' => $label,
                'langcode' => 'en',
            ], $extras));
            $storage->save($entity);
            $ids[] = $entity->id();
        }

        $loaded = $storage->loadMultiple($ids);

        self::assertCount(4, $loaded);
        self::assertSame('a@a.example', $loaded[$ids[0]]->get('email'));
        self::assertSame('2', $loaded[$ids[1]]->get('phone'));
        self::assertSame('https://o1.example', $loaded[$ids[2]]->get('website'));
        self::assertSame('O-2', $loaded[$ids[3]]->get('org_code'));
    }

    /**
     * Test 3: core fields that are not base-table columns fall through to
     * the _data JSON blob on save and merge back on load. Bundle partitioning
     * must not short-circuit splitForStorage's existing fallback.
     */
    #[Test]
    public function coreFieldsFallBackToDataBlobWhenNotSchemaColumns(): void
    {
        $this->registerBusinessFields();
        $this->ensureSchema(['business']);
        $storage = $this->makeStorage();

        $entity = $storage->create([
            'uuid' => 'uuid-x',
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'hi@acme.example',
            'description' => 'No schema column for me',
            'tags' => ['foo', 'bar'],
        ]);
        $storage->save($entity);

        $loaded = $storage->load($entity->id());
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
        $storage = $this->makeStorage();

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
        $gatewayMethod = new \ReflectionMethod(SqlEntityStorage::class, 'bundleGateway');
        $gateway = $gatewayMethod->invoke($storage);
        self::assertNotNull($gateway);
        $existsProp = new \ReflectionProperty($gateway::class, 'existsCache');
        $existsProp->setValue($gateway, ['business' => true]);
        $this->database->getConnection()->executeStatement('DROP TABLE "group__business"');

        $entity = $storage->create([
            'uuid' => 'uuid-fail',
            'type' => 'business',
            'label' => 'Doomed',
            'langcode' => 'en',
            'email' => 'doomed@acme.example',
        ]);

        $caught = false;
        try {
            $storage->save($entity);
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
     * Test 5: attempting to save a field registered against a different
     * bundle throws — the partitioner refuses to write silently-corrupt data.
     */
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
        $storage = $this->makeStorage($logger);

        $this->database->getConnection()->executeStatement('DROP TABLE "group__business"');

        $entity = $storage->create([
            'uuid' => 'uuid-missing-subtable',
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'hi@acme.example',
        ]);

        $storage->save($entity);

        self::assertCount(1, $messages);
        self::assertStringContainsString('[MISSING_BUNDLE_SUBTABLE]', $messages[0]);
        self::assertStringContainsString('entity type "group" bundle "business"', $messages[0]);
        self::assertStringContainsString('"group__business"', $messages[0]);
        self::assertStringContainsString('_data', $messages[0], 'the fallback path is named in the warning');

        // Never a silent drop: the bundle value is folded into the base `_data`
        // blob and recovered on load.
        $loaded = $storage->load($entity->id());
        self::assertNotNull($loaded);
        self::assertTrue($loaded->hasField('email'), 'the value is recovered from the _data fallback');
        self::assertSame('hi@acme.example', $loaded->get('email'));
    }

    /**
     * Test 6: attempting to save a field registered against a different
     * bundle throws â€” the partitioner refuses to write silently-corrupt data.
     */
    #[Test]
    public function saveRejectsFieldsBelongingToOtherBundles(): void
    {
        $this->registerBusinessFields();
        $this->registerOrganizationFields();
        $this->ensureSchema(['business', 'organization']);
        $storage = $this->makeStorage();

        $entity = $storage->create([
            'uuid' => 'uuid-mix',
            'type' => 'business',
            'label' => 'Misrouted',
            'langcode' => 'en',
            'email' => 'ok@acme.example',
            'org_code' => 'LEAK',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('belongs to bundle "organization"');

        $storage->save($entity);
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
        $storage = $this->makeStorage();

        $org = $storage->create([
            'uuid' => 'uuid-empty',
            'type' => 'organization',
            'label' => 'Bare',
            'langcode' => 'en',
        ]);
        $storage->save($org);
        $orgId = $org->id();

        self::assertFalse(
            $this->database->schema()->tableExists('group__organization'),
            'empty bundle must not have a subtable',
        );

        $loaded = $storage->load($orgId);
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

        $storage = new SqlEntityStorage(
            $singleBundle,
            $this->database,
            $this->dispatcher,
            $this->registry,
        );

        $entity = $storage->create([
            'uuid' => 'uuid-thing',
            'label' => 'Solo',
            'langcode' => 'en',
        ]);
        $storage->save($entity);

        $loaded = $storage->load($entity->id());
        self::assertNotNull($loaded);
        self::assertSame('Solo', $loaded->label());
    }

    /**
     * Cross-path unification: write an entity through the EntityRepository (the
     * migration / canonical write path) and read it back through SqlEntityStorage
     * (the admin/API getStorage() path). Both share the single
     * {@see \Waaseyaa\EntityStorage\Bundle\BundleSubtableGateway}, so the bundle
     * values round-trip identically from the typed subtable columns, never the
     * `_data` blob.
     */
    #[Test]
    public function bundleValuesRoundTripAcrossRepositoryWriteAndStorageRead(): void
    {
        $this->registerBusinessFields();
        $this->ensureSchema(['business']);

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'gid');
        $repository = new EntityRepository(
            $this->groupType,
            $driver,
            $this->dispatcher,
            database: $this->database,
            fieldRegistry: $this->registry,
        );
        $storage = $this->makeStorage();

        // WRITE through the repository.
        $entity = $storage->create([
            'uuid' => 'uuid-xpath',
            'type' => 'business',
            'label' => 'Acme',
            'langcode' => 'en',
            'email' => 'cross@acme.example',
            'phone' => '555-0199',
        ]);
        $repository->save($entity, validate: false);
        $id = $entity->id();
        self::assertNotNull($id);
        $id = (int) $id;

        // The values landed in the subtable COLUMNS, not the base `_data` blob.
        $sub = \iterator_to_array(
            $this->database->query('SELECT email, phone FROM "group__business" WHERE gid = ' . $id, []),
        );
        self::assertCount(1, $sub, 'a subtable row was written by the repository path');
        self::assertSame('cross@acme.example', ((array) $sub[0])['email']);
        self::assertSame('555-0199', ((array) $sub[0])['phone']);

        $baseRows = \iterator_to_array(
            $this->database->query('SELECT _data FROM "group" WHERE gid = ' . $id, []),
        );
        $data = \json_decode((string) (((array) $baseRows[0])['_data'] ?? '{}'), true) ?: [];
        self::assertArrayNotHasKey('email', $data, 'bundle value must not leak into _data');
        self::assertArrayNotHasKey('phone', $data, 'bundle value must not leak into _data');

        // READ through the storage path: identical round-trip from the columns.
        $loaded = $storage->load($id);
        self::assertNotNull($loaded);
        self::assertSame('cross@acme.example', $loaded->get('email'));
        self::assertSame('555-0199', $loaded->get('phone'));
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

    private function makeStorage(?LoggerInterface $logger = null): SqlEntityStorage
    {
        return new SqlEntityStorage(
            $this->groupType,
            $this->database,
            $this->dispatcher,
            $this->registry,
            $logger,
        );
    }
}

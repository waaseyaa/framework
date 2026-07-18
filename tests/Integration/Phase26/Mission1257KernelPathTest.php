<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase26;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Community\HasCommunityInterface;
use Waaseyaa\Entity\Community\HasCommunityTrait;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Exception\EntityTypeRegistrationCollisionException;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\Community\CommunityContext;
use Waaseyaa\Foundation\Diagnostic\BootDiagnosticReport;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

/**
 * Mission #1257 (entity-storage-hardening) WP11 — kernel-path integration lock.
 *
 * The mission's charter calls for a single end-to-end test that exercises
 * every hardened invariant in one place. WP11 is the lock; the mission does
 * not accept without it. Each test method below pins one of the hardened
 * conventions (K1-K7) at the same boundary the production runtime crosses:
 *
 *   - K1 (WP03): bundle subtable naming flows through the canonical static
 *     helper, and the structural guard rejects bundle ids carrying the
 *     reserved separator at registration time.
 *   - K2 (WP04): a `FieldStorage::Data` core field round-trips through the
 *     `_data` JSON blob on both write and read paths; legacy columns cannot
 *     shadow the registry hint.
 *   - K3 (WP05): query-builder conditions against integer-typed `_data`
 *     fields coerce numeric-string values per the declared FieldDefinition
 *     type, and IN-set elements are coerced individually.
 *   - K4 (WP06): when a registered bundle's subtable is absent at load time,
 *     `EntityRepository` emits a single `[MISSING_BUNDLE_SUBTABLE]` notice
 *     per `(entity_type, bundle)` for the lifetime of the storage instance.
 *   - K6 (WP08): `HealthChecker` (kernel-adjacent L0 component, exempted via
 *     `bin/check-package-layers`) surfaces both `MISSING_BUNDLE_SUBTABLE`
 *     and `ORPHAN_BUNDLE_SUBTABLE` diagnostic codes from `checkSchemaDrift()`.
 *   - K7 (WP07-B): `EntityTypeRegistrationCollisionException::duplicate()`
 *     names both registrants and both classes in its message body.
 *   - C1 (WP10): `EntityType` carries a declarative `tenancy` slot;
 *     `EntityTypeManager` emits a one-time `HasCommunityInterface`
 *     deprecation warning when the slot is null but the class still
 *     implements the legacy marker; `AbstractKernel`'s repository
 *     factory consults `getTenancy()` and wires `CommunityScope` into
 *     `SqlStorageDriver` when a `CommunityContextInterface` is bound,
 *     while logging a once-per-type warning when tenancy is declared
 *     but no context is bound.
 *
 * Components are wired in the same order `AbstractKernel` wires them. No
 * filesystem fixture or kernel boot is required — the integration is the
 * sequence (registry → schema → storage → query → health-check), not the
 * provider-discovery shell. The C1 wiring assertions reach into
 * `AbstractKernel` via a minimal test subclass that exposes only the
 * `bootEntityTypeManager()` step, so the production wiring stays the
 * subject under test.
 */
#[CoversNothing]
final class Mission1257KernelPathTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'mission1257_widget';
    private const string BUNDLE_ENTITY_TYPE_ID = 'mission1257_widget_type';

    private DBALDatabase $database;
    private FieldDefinitionRegistry $registry;
    private EntityTypeManager $entityTypeManager;
    private EntityType $entityType;
    private SqlSchemaHandler $schemaHandler;
    private EntityRepository $storage;
    private Mission1257SpyLogger $logger;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:');
        $this->registry = new FieldDefinitionRegistry();
        $this->logger = new Mission1257SpyLogger();

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            null,
            null,
            $this->registry,
        );

        $this->entityType = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Mission #1257 Widget',
            class: Mission1257Widget::class,
            keys: [
                'id' => 'wid',
                'uuid' => 'uuid',
                'bundle' => 'type',
                'label' => 'name',
                'langcode' => 'langcode',
            ],
            bundleEntityType: self::BUNDLE_ENTITY_TYPE_ID,
        );

        $this->entityTypeManager->registerEntityType($this->entityType, registrant: self::class);

        // Register a `FieldStorage::Data`-stored core integer field. This is
        // the field WP04 (read symmetry) and WP05 (numeric coercion) both
        // exercise. Registered through the registry directly because
        // `#[Field]` does not yet carry a `stored:` parameter.
        $this->registry->registerCoreFields(
            self::ENTITY_TYPE_ID,
            [
                'rank' => new FieldDefinition(
                    name: 'rank',
                    type: 'integer',
                    targetEntityTypeId: self::ENTITY_TYPE_ID,
                    defaultValue: 0,
                    label: 'Rank',
                    stored: FieldStorage::Data,
                ),
            ],
        );

        $this->schemaHandler = new SqlSchemaHandler(
            $this->entityType,
            $this->database,
            $this->registry,
        );
        $this->schemaHandler->ensureTable();

        // C-22 WP4: legacy SqlEntityStorage engine is deleted; the canonical
        // EntityRepository mirrors its bundle-subtable / query surface exactly
        // (see EntityRepository::getQuery() docblock), so the K1-K7/C1 wiring
        // assertions below still exercise the same behavioural contract.
        $resolver = new SingleConnectionResolver($this->database);
        $idKey = $this->entityType->getKeys()['id'] ?? 'id';
        $this->storage = new EntityRepository(
            $this->entityType,
            new SqlStorageDriver($resolver, $idKey),
            new EventDispatcher(),
            database: $this->database,
            fieldRegistry: $this->registry,
            logger: $this->logger,
        );

        // HealthChecker requires a project root with `storage/framework/`.
        $this->projectRoot = sys_get_temp_dir() . '/wp11_kernel_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0o755, true);
    }

    protected function tearDown(): void
    {
        // Clear the FieldDefinitionRegistry singleton on ContentEntityBase so
        // subsequent tests do not see this fixture's registry leak across the
        // class-level static.
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);

        if (!isset($this->projectRoot) || !is_dir($this->projectRoot)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->projectRoot);
    }

    // ------------------------------------------------------------------
    // K1 (WP03) — bundle subtable naming
    // ------------------------------------------------------------------

    #[Test]
    public function k1_resolveSubtableNameProducesCanonicalDoubleUnderscoreFormat(): void
    {
        self::assertSame(
            self::ENTITY_TYPE_ID . '__alpha',
            SqlSchemaHandler::resolveSubtableName(self::ENTITY_TYPE_ID, 'alpha'),
        );

        self::assertSame(
            self::ENTITY_TYPE_ID . '__alpha',
            SqlSchemaHandler::resolveSubtableName(self::ENTITY_TYPE_ID, 'alpha', self::ENTITY_TYPE_ID),
        );
    }

    #[Test]
    public function k1_addBundleFieldsRejectsReservedSeparatorInBundleId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved separator "__"');

        $this->entityTypeManager->addBundleFields(
            self::ENTITY_TYPE_ID,
            'alpha__nested',
            [
                'gizmo_code' => new FieldDefinition(
                    name: 'gizmo_code',
                    type: 'string',
                    targetEntityTypeId: self::ENTITY_TYPE_ID,
                    targetBundle: 'alpha__nested',
                ),
            ],
        );
    }

    // ------------------------------------------------------------------
    // K2 (WP04) — read/write symmetry for FieldStorage::Data
    // ------------------------------------------------------------------

    #[Test]
    public function k2_dataStoredCoreFieldRoundTripsThroughDataBlob(): void
    {
        $this->entityTypeManager->addBundleFields(self::ENTITY_TYPE_ID, 'alpha', [
            'gizmo_code' => new FieldDefinition(
                name: 'gizmo_code',
                type: 'string',
                targetEntityTypeId: self::ENTITY_TYPE_ID,
                targetBundle: 'alpha',
            ),
        ]);
        $this->schemaHandler->ensureTable();

        $entity = new Mission1257Widget([
            'name' => 'Symmetric',
            'type' => 'alpha',
            'rank' => 7,
            'gizmo_code' => 'SYM-1',
        ]);
        $this->storage->save($entity, validate: false);

        // Write side: `rank` lives in `_data`, never as a base column.
        $row = $this->database->getConnection()->fetchAssociative(
            'SELECT _data FROM "' . self::ENTITY_TYPE_ID . '" WHERE wid = :wid',
            ['wid' => $entity->id()],
        );
        self::assertIsArray($row);

        $data = json_decode((string) $row['_data'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('rank', $data);
        self::assertSame(7, $data['rank']);

        $columns = $this->database->getConnection()->fetchAllAssociative(
            'PRAGMA table_info("' . self::ENTITY_TYPE_ID . '")',
        );
        $columnNames = array_column($columns, 'name');
        self::assertNotContains(
            'rank',
            $columnNames,
            'FieldStorage::Data fields must not be materialized as base columns.',
        );

        // Read side: condition() resolves via `json_extract(_data, ...)`,
        // never through a column lookup.
        $ids = $this->storage->getQuery()->accessCheck(false)
            ->condition('rank', 7)
            ->execute();
        self::assertSame([(int) $entity->id()], $ids);
    }

    // ------------------------------------------------------------------
    // K3 (WP05) — _data value coercion in query builder
    // ------------------------------------------------------------------

    #[Test]
    public function k3_conditionCoercesNumericStringToDeclaredIntegerType(): void
    {
        $entity = new Mission1257Widget([
            'name' => 'Coerce',
            'type' => 'alpha',
            'rank' => 13,
        ]);
        $this->storage->save($entity, validate: false);

        // Control: integer binding works.
        $idsInt = $this->storage->getQuery()->accessCheck(false)
            ->condition('rank', 13)
            ->execute();
        self::assertSame([(int) $entity->id()], $idsInt, 'integer binding (control)');

        // The mission anchor (#1257): numeric-string against integer-typed
        // `_data` field. Pre-WP05 returned no rows.
        $idsString = $this->storage->getQuery()->accessCheck(false)
            ->condition('rank', '13')
            ->execute();
        self::assertSame(
            [(int) $entity->id()],
            $idsString,
            'numeric-string condition() must coerce per declared FieldDefinition type — '
            . 'callers must not need to know storage shape (Minoo `(int)` workaround removable).',
        );
    }

    #[Test]
    public function k3_inSetAgainstDataIntegerFieldCoercesEachElement(): void
    {
        $a = new Mission1257Widget(['name' => 'A', 'type' => 'alpha', 'rank' => 1]);
        $b = new Mission1257Widget(['name' => 'B', 'type' => 'alpha', 'rank' => 2]);
        $c = new Mission1257Widget(['name' => 'C', 'type' => 'alpha', 'rank' => 3]);
        $this->storage->save($a, validate: false);
        $this->storage->save($b, validate: false);
        $this->storage->save($c, validate: false);

        $ids = $this->storage->getQuery()->accessCheck(false)
            ->condition('rank', ['1', 3], 'IN')
            ->execute();
        sort($ids);

        self::assertSame([(int) $a->id(), (int) $c->id()], $ids);
    }

    // ------------------------------------------------------------------
    // K4 (WP06) — bundle-load drift logging
    // ------------------------------------------------------------------

    /**
     * C-22 WP4 note: this method used to also assert a load-side
     * `[MISSING_BUNDLE_SUBTABLE]` notice (mirroring the deleted
     * `SqlEntityStorage::mergeBundleSubtableRow()`'s private logging). That
     * notice has no `EntityRepository` equivalent — an accepted, documented
     * behavior loss (see `docs/notes/c22-consumer-inventory.md` §7 and the
     * deletion of `SqlEntityStorageBundleLoadDriftTest.php`): the load path
     * silently skips a missing bundle subtable with no operator-facing
     * notice, while the more load-bearing SAVE-side notice
     * (`BundleSubtableGateway::logMissingSubtableOnSave()`) is preserved and
     * still tested. The load-non-fatal assertion below remains valid and is
     * kept; the notice-cadence/memoization assertions were removed.
     */
    #[Test]
    public function k4_loadDoesNotFailWhenBundleSubtableIsMissing(): void
    {
        // Register the bundle AFTER the schema is materialized — the
        // registry knows about the bundle, but the subtable was never
        // created.
        $this->entityTypeManager->addBundleFields(self::ENTITY_TYPE_ID, 'alpha', [
            'gizmo_code' => new FieldDefinition(
                name: 'gizmo_code',
                type: 'string',
                targetEntityTypeId: self::ENTITY_TYPE_ID,
                targetBundle: 'alpha',
            ),
        ]);

        // Seed two `alpha` rows directly to bypass the save-side notice and
        // isolate the load-side non-fatal assertion.
        $this->database->insert(self::ENTITY_TYPE_ID)
            ->fields(['uuid', 'type', 'name', 'langcode', '_data'])
            ->values(['uuid' => 'a', 'type' => 'alpha', 'name' => 'A', 'langcode' => 'en', '_data' => '{}'])
            ->execute();
        $this->database->insert(self::ENTITY_TYPE_ID)
            ->fields(['uuid', 'type', 'name', 'langcode', '_data'])
            ->values(['uuid' => 'b', 'type' => 'alpha', 'name' => 'B', 'langcode' => 'en', '_data' => '{}'])
            ->execute();

        $entities = $this->storage->findMany([1, 2]);
        self::assertCount(2, $entities, 'Base rows still load when subtable is missing — drift is non-fatal.');
    }

    // ------------------------------------------------------------------
    // K6 (WP08) — HealthChecker layer-exempt diagnostics
    // ------------------------------------------------------------------

    #[Test]
    public function k6_healthCheckerSurfacesMissingBundleSubtableDiagnostic(): void
    {
        $this->entityTypeManager->addBundleFields(self::ENTITY_TYPE_ID, 'alpha', [
            'gizmo_code' => new FieldDefinition(
                name: 'gizmo_code',
                type: 'string',
                targetEntityTypeId: self::ENTITY_TYPE_ID,
                targetBundle: 'alpha',
            ),
        ]);
        // Intentionally do NOT call ensureTable() again — leaves the subtable
        // absent, which is exactly what `MISSING_BUNDLE_SUBTABLE` reports.

        $health = $this->newHealthChecker();
        $codes = self::diagnosticCodes($health->checkSchemaDrift());

        self::assertContains(
            DiagnosticCode::MISSING_BUNDLE_SUBTABLE,
            $codes,
            'HealthChecker must surface MISSING_BUNDLE_SUBTABLE when a registered bundle has no subtable.',
        );
    }

    #[Test]
    public function k6_healthCheckerSurfacesOrphanBundleSubtableDiagnostic(): void
    {
        // Materialize an unregistered subtable directly via DDL — simulating
        // a bundle whose fields were removed from the registry but whose
        // storage table lingers.
        $orphanTable = self::ENTITY_TYPE_ID . '__ghost';
        $this->database->getConnection()->executeStatement(
            'CREATE TABLE "' . $orphanTable . '" (wid INTEGER PRIMARY KEY)',
        );

        $health = $this->newHealthChecker();
        $codes = self::diagnosticCodes($health->checkSchemaDrift());

        self::assertContains(
            DiagnosticCode::ORPHAN_BUNDLE_SUBTABLE,
            $codes,
            'HealthChecker must surface ORPHAN_BUNDLE_SUBTABLE for a subtable matching the {base}__% pattern with no registered bundle fields.',
        );
    }

    // ------------------------------------------------------------------
    // K7 (WP07-B) — duplicate-registration message names both registrants
    // ------------------------------------------------------------------

    #[Test]
    public function k7_duplicateRegistrationExceptionNamesBothRegistrantsAndClasses(): void
    {
        $duplicateType = new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Same class, second registration',
            class: Mission1257Widget::class,
            keys: $this->entityType->getKeys(),
            bundleEntityType: self::BUNDLE_ENTITY_TYPE_ID,
        );

        try {
            $this->entityTypeManager->registerEntityType(
                $duplicateType,
                registrant: 'SecondRegistrant',
            );
            self::fail('Expected EntityTypeRegistrationCollisionException');
        } catch (EntityTypeRegistrationCollisionException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('[ENTITY_TYPE_DUPLICATE]', $message);
            self::assertStringContainsString(self::ENTITY_TYPE_ID, $message);
            self::assertStringContainsString(self::class, $message, 'Existing registrant must be named.');
            self::assertStringContainsString('SecondRegistrant', $message, 'Incoming registrant must be named.');
            self::assertStringContainsString(Mission1257Widget::class, $message, 'Both registrant classes must appear.');
        }
    }

    // ------------------------------------------------------------------
    // C1 (WP10) — declarative tenancy via EntityType
    // ------------------------------------------------------------------

    #[Test]
    public function c1_entityTypeCarriesTenancySlotEndToEnd(): void
    {
        $tenantType = new EntityType(
            id: 'mission1257_tenant_widget',
            label: 'Mission #1257 Tenant Widget',
            class: Mission1257TenantWidget::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name', 'langcode' => 'langcode'],
            tenancy: ['scope' => 'community'],
        );

        self::assertSame(['scope' => 'community'], $tenantType->getTenancy());
        self::assertNull(
            $this->entityType->getTenancy(),
            'Untenant types must report null tenancy — the slot is opt-in.',
        );
    }

    #[Test]
    public function c1_entityTypeManagerWarnsOnceWhenLegacyMarkerStillCarriedAndTenancyNull(): void
    {
        $tenantType = new EntityType(
            id: 'mission1257_legacy_tenant',
            label: 'Legacy tenant',
            class: Mission1257LegacyMarkerEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
        );

        $this->entityTypeManager->registerEntityType($tenantType, registrant: self::class);

        // EntityTypeManager logs through the logger passed to its constructor.
        // The WP11 setUp() does not pass a logger, so we re-register with a
        // logger-aware manager to lock the deprecation contract directly.
        $loggerAware = new EntityTypeManager(
            new EventDispatcher(),
            null,
            null,
            null,
            $this->logger,
        );
        $loggerAware->registerEntityType(new EntityType(
            id: 'mission1257_legacy_tenant_again',
            label: 'Legacy tenant (logger-aware)',
            class: Mission1257LegacyMarkerEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
        ));

        $matches = $this->logger->messagesContaining('HasCommunityInterface');
        self::assertCount(
            1,
            $matches,
            'Mission #1257 §C1: registering a legacy-marker entity type without a tenancy slot must emit '
            . 'exactly one [deprecated] HasCommunityInterface warning per type id.',
        );
        self::assertStringContainsString('mission1257_legacy_tenant_again', $matches[0]);
        self::assertStringContainsString("tenancy: ['scope' => 'community']", $matches[0]);
    }

    #[Test]
    public function c1_entityTypeManagerStaysSilentWhenTenancySlotDeclared(): void
    {
        $loggerAware = new EntityTypeManager(
            new EventDispatcher(),
            null,
            null,
            null,
            $this->logger,
        );

        $loggerAware->registerEntityType(new EntityType(
            id: 'mission1257_migrated_tenant',
            label: 'Migrated tenant',
            class: Mission1257LegacyMarkerEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            tenancy: ['scope' => 'community'],
        ));

        self::assertSame(
            [],
            $this->logger->messagesContaining('HasCommunityInterface'),
            'A migrated tenancy slot must silence the legacy-marker deprecation warning, '
            . 'even when the class still carries the marker for the deprecation cycle.',
        );
    }

    #[Test]
    public function c1_kernelInjectsCommunityScopeWhenTenancyDeclaredAndContextBound(): void
    {
        $kernel = $this->newTenancyTestKernel();
        $kernel->publicBootDatabase();

        $context = new CommunityContext();
        $context->set('community-alpha');
        $kernel->setCommunityContext($context);

        $kernel->publicBootEntityTypeManager();

        $tenantType = new EntityType(
            id: 'mission1257_kernel_tenant',
            label: 'Kernel-wired tenant',
            class: Mission1257TenantWidget::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name', 'langcode' => 'langcode'],
            tenancy: ['scope' => 'community'],
        );
        $kernel->publicEntityTypeManager()->registerEntityType($tenantType, registrant: self::class);

        $repository = $kernel->publicEntityTypeManager()->getRepository('mission1257_kernel_tenant');

        $driver = self::extractDriver($repository);
        $scope = self::extractCommunityScope($driver);

        self::assertInstanceOf(
            CommunityScope::class,
            $scope,
            'Mission #1257 §C1: the kernel must inject CommunityScope into SqlStorageDriver '
            . 'when EntityType declares tenancy [scope=>community] and a CommunityContextInterface is bound.',
        );
        self::assertTrue($scope->isActive());
        self::assertSame('community-alpha', $scope->getCommunityId());
    }

    #[Test]
    public function c1_kernelLogsOnceWhenTenancyDeclaredButContextMissingInDevelopment(): void
    {
        // In development environments the kernel must NOT crash on missing
        // context — tests, CLI, and bare bootstrap routinely run without a
        // bound CommunityContextInterface.
        $kernel = $this->newTenancyTestKernel(environment: 'local');
        $kernel->publicBootDatabase();
        $kernel->publicBootEntityTypeManager();

        $tenantType = new EntityType(
            id: 'mission1257_unbound_tenant',
            label: 'Unbound tenant',
            class: Mission1257TenantWidget::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name', 'langcode' => 'langcode'],
            tenancy: ['scope' => 'community'],
        );
        $kernel->publicEntityTypeManager()->registerEntityType($tenantType, registrant: self::class);

        // Trigger the factory twice to prove memoization (one warning, not two).
        $repositoryA = $kernel->publicEntityTypeManager()->getRepository('mission1257_unbound_tenant');
        $repositoryB = $kernel->publicEntityTypeManager()->getRepository('mission1257_unbound_tenant');

        self::assertSame($repositoryA, $repositoryB);

        $driver = self::extractDriver($repositoryA);
        self::assertNull(
            self::extractCommunityScope($driver),
            'In development, missing CommunityContextInterface must fall back to a null scope '
            . 'rather than crashing the boot path.',
        );

        $matches = $this->logger->messagesContaining('mission1257_unbound_tenant');
        self::assertCount(
            1,
            $matches,
            'Mission #1257 §C1: missing CommunityContextInterface in development must produce '
            . 'exactly one kernel-side warning per entity-type id.',
        );
        self::assertStringContainsString('CommunityContextInterface', $matches[0]);
        self::assertStringContainsString('setCommunityContext', $matches[0]);
    }

    #[Test]
    public function c1_kernelThrowsInProductionWhenTenancyDeclaredButContextMissing(): void
    {
        // Mission #1257 §C1 / WP10 review feedback: in production, declaring
        // tenancy without binding a CommunityContextInterface is a data-leak
        // posture (every read goes through with no community filter), not a
        // tolerable misconfiguration. Refuse to construct the repository —
        // fail loud, not silent.
        $kernel = $this->newTenancyTestKernel(environment: 'production');
        $kernel->publicBootDatabase();
        $kernel->publicBootEntityTypeManager();

        $tenantType = new EntityType(
            id: 'mission1257_prod_unbound_tenant',
            label: 'Production tenant without context',
            class: Mission1257TenantWidget::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name', 'langcode' => 'langcode'],
            tenancy: ['scope' => 'community'],
        );
        $kernel->publicEntityTypeManager()->registerEntityType($tenantType, registrant: self::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TENANCY_MISCONFIGURED/');

        $kernel->publicEntityTypeManager()->getRepository('mission1257_prod_unbound_tenant');
    }

    /**
     * Reach into an EntityRepository to inspect the storage driver it wraps.
     *
     * The C1 wiring contract (mission #1257 §C1 / WP10) is internal: the
     * kernel's repository factory builds an `EntityRepository` whose driver
     * is a `SqlStorageDriverV2` whose SQL backend carries — or lacks — a
     * `CommunityScope`.
     * No public accessor exists, by design (the driver is an implementation
     * detail). Reflection here is the cost of locking the wiring decision
     * at the kernel boundary without leaking accessors into production.
     */
    private static function extractDriver(object $repository): SqlStorageDriver
    {
        $property = new \ReflectionProperty($repository, 'driver');
        $driver = $property->getValue($repository);
        self::assertInstanceOf(SqlStorageDriverV2::class, $driver);

        $backend = new \ReflectionProperty($driver, 'backend');
        $value = $backend->getValue($driver);
        self::assertInstanceOf(SqlStorageDriver::class, $value);

        return $value;
    }

    private static function extractCommunityScope(SqlStorageDriver $driver): ?CommunityScope
    {
        $property = new \ReflectionProperty($driver, 'communityScope');
        $value = $property->getValue($driver);

        if ($value === null) {
            return null;
        }

        self::assertInstanceOf(CommunityScope::class, $value);
        return $value;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build an anonymous {@see AbstractKernel} subclass that exposes only
     * the boot steps WP10 wires (database + entity-type manager). Returning
     * an anonymous class satisfies the architectural rule
     * `NoKernelSubclassesInTestsTest` while still letting the C1 tests
     * exercise the production wiring decision in `bootEntityTypeManager()`.
     *
     * The kernel-bootstrap exemption documented in the project CLAUDE.md
     * (kernels intentionally import from all layers) applies here: this
     * fixture mirrors the same wiring surface as `ConsoleKernel` /
     * `HttpKernel`, just without the boot orchestration.
     */
    private function newTenancyTestKernel(string $environment = 'local'): object
    {
        return new class ($this->projectRoot, $this->logger, $environment) extends AbstractKernel {
            public function __construct(string $projectRoot, LoggerInterface $logger, string $environment)
            {
                parent::__construct($projectRoot, $logger);
                // DatabaseBootstrapper reads `config.database` as a path string.
                // `environment` drives AbstractKernel::isDevelopmentMode(),
                // which gates the production-strict tenancy guard.
                $this->config = [
                    'database' => ':memory:',
                    'environment' => $environment,
                ];
                // boot() seeds the EventDispatcher before bootDatabase() and
                // bootEntityTypeManager() consume it. Reproduce the bare
                // minimum so the tests can drive the wiring steps without
                // running provider discovery or manifest compilation.
                $this->dispatcher = new SymfonyEventDispatcherAdapter();
            }

            public function publicBootDatabase(): void
            {
                $this->bootDatabase();
            }

            public function publicBootEntityTypeManager(): void
            {
                $this->bootEntityTypeManager();
            }

            public function publicEntityTypeManager(): EntityTypeManager
            {
                return $this->entityTypeManager;
            }
        };
    }

    private function newHealthChecker(): HealthChecker
    {
        $bootReport = new BootDiagnosticReport(
            registeredTypes: [self::ENTITY_TYPE_ID => $this->entityType],
            disabledTypeIds: [],
            schemaCompatibility: [],
        );

        return new HealthChecker(
            bootReport: $bootReport,
            database: $this->database,
            entityTypeManager: $this->entityTypeManager,
            projectRoot: $this->projectRoot,
            logger: $this->logger,
            fieldRegistry: $this->registry,
        );
    }

    /**
     * @param iterable<\Waaseyaa\Foundation\Diagnostic\HealthCheckResult> $results
     * @return list<DiagnosticCode>
     */
    private static function diagnosticCodes(iterable $results): array
    {
        $codes = [];
        foreach ($results as $result) {
            if ($result->code !== null) {
                $codes[] = $result->code;
            }
        }
        return $codes;
    }
}

#[ContentEntityType(id: 'mission1257_widget')]
#[ContentEntityKeys(id: 'wid', uuid: 'uuid', bundle: 'type', label: 'name', langcode: 'langcode')]
final class Mission1257Widget extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

/**
 * In-memory logger used by both `EntityRepository` (load-side notices) and
 * `HealthChecker` (drift logging) within this fixture. Messages are stored
 * verbatim so tests can assert on the diagnostic code prefix.
 */
final class Mission1257SpyLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $messages = [];

    /**
     * @return list<string>
     */
    public function messagesContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn(string $m): bool => str_contains($m, $needle),
        ));
    }

    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}

/**
 * Test fixture for C1 (WP10) tenancy assertions. Carries no
 * `HasCommunityInterface` marker — tenancy is declared at the EntityType
 * registration site.
 */
#[ContentEntityType(id: 'mission1257_tenant_widget')]
#[ContentEntityKeys(id: 'wid', uuid: 'uuid', label: 'name', langcode: 'langcode')]
final class Mission1257TenantWidget extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

/**
 * Test fixture for C1 (WP10) deprecation-cycle assertions. Implements the
 * legacy {@see HasCommunityInterface} marker so the EntityTypeManager can
 * recognize the pre-mission shape and emit the once-per-type deprecation
 * warning.
 */
final class Mission1257LegacyMarkerEntity extends ContentEntityBase implements HasCommunityInterface
{
    use HasCommunityTrait;
}

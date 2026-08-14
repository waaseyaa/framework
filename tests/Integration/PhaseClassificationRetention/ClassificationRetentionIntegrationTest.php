<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseClassificationRetention;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Field\Classification\ClassificationParentResolverInterface;
use Waaseyaa\Field\Classification\ClassificationSubjectReader;
use Waaseyaa\Field\Classification\Job\HoldScanJob;
use Waaseyaa\Field\Classification\Job\PurgeJob;
use Waaseyaa\Field\Classification\LabelInheritanceResolver;
use Waaseyaa\Field\Classification\Permissions;
use Waaseyaa\Field\Classification\Policy\ClassificationFieldAccessPolicy;
use Waaseyaa\Field\Entity\ClassificationLabelDefinition;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\FieldServiceProvider;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * FR-015 — end-to-end integration of the classification + retention substrate
 * over a BOOTED KERNEL.
 *
 * Unlike a unit test, every collaborator here is wired by the real kernel boot
 * sequence: {@see FieldServiceProvider} registers the classification entity
 * types and binds the label registry + clearance checker; the kernel's
 * {@see \Waaseyaa\Foundation\Kernel\Bootstrap\KernelPolicyDependencyResolver}
 * auto-discovers {@see ClassificationFieldAccessPolicy} (via its
 * `#[PolicyAttribute(entityType: '*')]`) and injects those bindings; the
 * field-package migrations materialise the `retention_policy` and
 * `classification_label_definition` tables; and the retention jobs run against
 * the kernel-resolved {@see EntityTypeManager} + container-bound
 * {@see AuditWriterInterface}. Nothing in the access / job path is hand-rolled.
 *
 * The test asserts the FR-015 sub-assertions (a)–(d) plus the purge and
 * hold-scan retention-job scenarios mandated by task T-R.
 *
 * ====================================================================
 * DEAD-CODE / SECURITY GUARD — LOAD-BEARING (reviewer verifies by hand)
 * ====================================================================
 * The hold-block assertion in {@see hold_label_blocks_admin_without_bypass}
 * is the teeth of C-004 / FR-013. It exercises the access policy through the
 * REAL kernel-discovered registration, NOT a hand-constructed instance.
 *
 * VERIFIED LOAD-BEARING LINE — comment this out and re-run this test:
 *
 *   packages/field/src/FieldServiceProvider.php  (FieldServiceProvider::register)
 *   → `$this->singleton(ClassificationClearanceCheckerInterface::class,
 *        fn() => new RoleBasedClearanceChecker());`
 *
 * Removing that binding makes the kernel's KernelPolicyDependencyResolver
 * unable to satisfy the `clearance` constructor parameter of
 * {@see ClassificationFieldAccessPolicy}; boot throws
 * `PolicyInstantiationException`, the access handler never receives the policy,
 * and {@see hold_label_blocks_admin_without_bypass} (and the clearance-gate
 * assertions) FAIL. Proven by-hand during WP03 fix cycle 1.
 *
 * The related production discovery signal —
 *   packages/field/src/Classification/Policy/ClassificationFieldAccessPolicy.php
 *   → `#[PolicyAttribute(entityType: '*')]`
 * — is what produces the manifest `policies` entry in a real install; this
 * test mirrors that entry in its hand-built manifest, so the binding above is
 * the line whose removal this test directly detects.
 *
 * The guard cannot be satisfied by editing this test file alone.
 * ====================================================================
 */
#[CoversNothing]
final class ClassificationRetentionIntegrationTest extends TestCase
{
    private const ENTITY_TYPE = 'fr015_document';

    private string $projectRoot = '';

    /** @var object{recorded: list<AuditEventDescriptor>}&AuditWriterInterface */
    private AuditWriterInterface $auditWriter;

    private AbstractKernel $kernel;

    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_fr015_' . uniqid();
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );

        $this->auditWriter = $this->recordingAuditWriter();
        $this->kernel = $this->bootKernel($this->projectRoot, $this->auditWriter);
        $this->entityTypeManager = $this->kernel->getEntityTypeManager();
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::entities(
            $this->kernel->getDatabase(),
            $this->entityTypeManager,
            $this->entityTypeManager->getDefinitions(),
        );

        $this->seedLabels();
    }

    protected function tearDown(): void
    {
        // Reset process-wide statics the kernel installs at boot.
        new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->setValue(null, null);

        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
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
    // (a) + (b) — inheritance cascade and explicit override
    // ------------------------------------------------------------------

    #[Test]
    public function child_without_explicit_label_inherits_parent_label(): void
    {
        $repository = $this->entityTypeManager->getRepository(self::ENTITY_TYPE);

        $parent = $this->newEntity(['uuid' => 'parent-uuid', 'classification_label' => 'confidential']);
        $repository->save($parent, validate: false);

        // The lifecycle subscriber (wired by FieldServiceProvider::boot) runs on
        // save and resolves inheritance from the parent for a child carrying a
        // parent pointer but no explicit label.
        $child = $this->newEntity(['uuid' => 'child-inherit-uuid', 'parent_uuid' => 'parent-uuid']);
        $repository->save($child, validate: false);

        $reloaded = $this->loadByUuid($repository, 'child-inherit-uuid');
        self::assertNotNull($reloaded);
        // (a) inheritance cascaded on first save through the booted pipeline.
        $subject = new ClassificationSubjectReader()->read($reloaded);
        self::assertSame('confidential', $subject->label);
        self::assertSame('parent-uuid', $subject->inheritedFrom);
    }

    #[Test]
    public function child_with_explicit_label_keeps_override_without_inheritance(): void
    {
        $repository = $this->entityTypeManager->getRepository(self::ENTITY_TYPE);

        $parent = $this->newEntity(['uuid' => 'parent-uuid', 'classification_label' => 'confidential']);
        $repository->save($parent, validate: false);

        // Child carries its own explicit label despite having a parent.
        $child = $this->newEntity([
            'uuid' => 'child-override-uuid',
            'parent_uuid' => 'parent-uuid',
            'classification_label' => 'public',
        ]);
        $repository->save($child, validate: false);

        $overridden = $this->loadByUuid($repository, 'child-override-uuid');
        self::assertNotNull($overridden);
        // (b) explicit override persists and does NOT inherit the parent label.
        $subject = new ClassificationSubjectReader()->read($overridden);
        self::assertSame('public', $subject->label);
        self::assertNull($subject->inheritedFrom);
    }

    #[Test]
    public function reinheriting_a_changed_label_stamps_overridden_at(): void
    {
        // T-R (b), overridden_at clause: the only path that stamps
        // classification_overridden_at is re-inheritance where the entity's
        // *previous* explicit label differs from the parent's. We drive the
        // REAL kernel-wired LabelInheritanceResolver (which carries the
        // production parent resolvers) — the same object the lifecycle
        // subscriber uses — with that previous label.
        $repository = $this->entityTypeManager->getRepository(self::ENTITY_TYPE);
        $parent = $this->newEntity(['uuid' => 'parent-uuid', 'classification_label' => 'confidential']);
        $repository->save($parent, validate: false);

        // Child now carries no explicit label (cleared for re-inheritance).
        $child = $this->newEntity(['uuid' => 'reinherit-uuid', 'parent_uuid' => 'parent-uuid']);

        $resolver = $this->labelInheritanceResolver();
        // previousLabel 'public' differs from the parent's 'confidential'.
        $decision = $resolver->resolve($child, previousLabel: 'public');

        self::assertSame('confidential', $decision->label, 'Re-inheritance adopts the parent label.');
        self::assertSame('parent-uuid', $decision->inheritedFromUuid);
        self::assertNotNull(
            $decision->overriddenAt,
            'T-R (b): a changed re-inheritance must stamp classification_overridden_at.',
        );
        // toStorageArray() is what the subscriber writes back to the entity.
        self::assertNotNull($decision->toStorageArray()['classification_overridden_at']);
    }

    // ------------------------------------------------------------------
    // (c) — clearance gate: anon forbidden, cleared admin neutral
    // ------------------------------------------------------------------

    #[Test]
    public function confidential_label_blocks_anonymous_read(): void
    {
        $entity = $this->newEntity(['uuid' => 'doc-c', 'classification_label' => 'confidential']);
        $result = $this->fieldAccess($entity, $this->anonymous());

        // (c) anonymous (clearance 0) < confidential (10) → forbidden.
        self::assertTrue($result->isForbidden(), "anonymous must not read confidential: {$result->reason}");
    }

    #[Test]
    public function confidential_label_is_neutral_for_cleared_admin(): void
    {
        $entity = $this->newEntity(['uuid' => 'doc-c', 'classification_label' => 'confidential']);
        $result = $this->fieldAccess($entity, $this->admin(bypass: false));

        // (c) admin (clearance 10) >= confidential (10) → neutral (not forbidden).
        self::assertFalse($result->isForbidden(), "admin must read confidential: {$result->reason}");
    }

    // ------------------------------------------------------------------
    // (d) + hold-block — hold overrides clearance both ways
    // ------------------------------------------------------------------

    #[Test]
    public function hold_label_allows_admin_with_bypass(): void
    {
        $entity = $this->newEntity(['uuid' => 'doc-h', 'classification_label' => 'hold-legal']);
        $result = $this->fieldAccess($entity, $this->admin(bypass: true));

        // (d) legal-hold-bypass holder may read held data.
        self::assertFalse($result->isForbidden(), "bypass holder must read hold-legal: {$result->reason}");
    }

    #[Test]
    public function hold_label_blocks_admin_without_bypass(): void
    {
        $entity = $this->newEntity(['uuid' => 'doc-h', 'classification_label' => 'hold-legal']);
        $result = $this->fieldAccess($entity, $this->admin(bypass: false));

        // C-004 / FR-013: hold overrides clearance — even a max-clearance admin
        // is blocked without the explicit legal-hold-bypass permission.
        // (See the LOAD-BEARING guard block at the top of this class.)
        self::assertTrue(
            $result->isForbidden(),
            "C-004/FR-013 violated: admin without bypass read a hold-legal entity: {$result->reason}",
        );
    }

    // ------------------------------------------------------------------
    // Purge scenario (T-R) — run PurgeJob through real composition
    // ------------------------------------------------------------------

    #[Test]
    public function purge_job_deletes_aged_public_entity_and_records_audit(): void
    {
        $repository = $this->entityTypeManager->getRepository(self::ENTITY_TYPE);

        // Old public entity: created 30 days ago, beyond the 7-day window.
        $old = $this->newEntity([
            'uuid' => 'old-public',
            'classification_label' => 'public',
            'created_at' => new \DateTimeImmutable('-30 days')->format('Y-m-d H:i:s'),
        ]);
        $repository->save($old, validate: false);

        // Fresh public entity: created today, inside the window — must survive.
        $fresh = $this->newEntity([
            'uuid' => 'fresh-public',
            'classification_label' => 'public',
            'created_at' => new \DateTimeImmutable('now')->format('Y-m-d H:i:s'),
        ]);
        $repository->save($fresh, validate: false);

        // Seed the 7-day age-based purge policy for `public`.
        $this->seedPolicy([
            'uuid' => 'purge-public-7d',
            'name' => 'Purge public after 7 days',
            'applies_to' => json_encode(['public'], JSON_THROW_ON_ERROR),
            'action' => RetentionPolicy::ACTION_PURGE,
            'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
            'trigger_value' => 'P7D',
        ]);

        $purgePolicyId = $this->loadPolicyIdByUuid('purge-public-7d');

        // Run the job through kernel-resolved EntityTypeManager + the bound writer.
        $job = new PurgeJob($this->entityTypeManager, $this->auditWriter);
        $job->run();

        self::assertNull(
            $this->loadByUuid($repository, 'old-public'),
            'T-R purge: the aged public entity must be deleted.',
        );
        self::assertNotNull(
            $this->loadByUuid($repository, 'fresh-public'),
            'T-R purge: a fresh entity inside the window must survive.',
        );

        $purges = $this->eventsOfKind(AuditEventKind::RetentionPurge);
        self::assertCount(1, $purges, 'T-R purge: exactly one retention.purge event expected.');
        self::assertSame(
            $purgePolicyId,
            $purges[0]->attributes['policy_id'] ?? null,
            'T-R purge: the retention.purge event must carry the driving policy_id.',
        );
    }

    // ------------------------------------------------------------------
    // Hold-scan scenario (T-R) — run HoldScanJob over a conflicting pair
    // ------------------------------------------------------------------

    #[Test]
    public function hold_scan_records_hold_vs_purge_conflict(): void
    {
        $repository = $this->entityTypeManager->getRepository(self::ENTITY_TYPE);

        // A held entity that is also matched by a purge policy (the conflict).
        $held = $this->newEntity([
            'uuid' => 'held-and-purgeable',
            'classification_label' => 'hold-legal',
            'created_at' => new \DateTimeImmutable('-30 days')->format('Y-m-d H:i:s'),
        ]);
        $repository->save($held, validate: false);

        // Conflicting policy pair: a hold-flag policy and a purge policy that
        // BOTH match the `hold-legal` label.
        $this->seedPolicy([
            'uuid' => 'hold-legal-flag',
            'name' => 'Hold legal',
            'applies_to' => json_encode(['hold-legal'], JSON_THROW_ON_ERROR),
            'action' => RetentionPolicy::ACTION_HOLD_FLAG,
            'trigger_kind' => RetentionPolicy::TRIGGER_EVENT_BASED,
            'trigger_value' => 'legal:hold',
        ]);
        $this->seedPolicy([
            'uuid' => 'purge-hold-legal',
            'name' => 'Purge hold-legal (misconfiguration)',
            'applies_to' => json_encode(['hold-legal'], JSON_THROW_ON_ERROR),
            'action' => RetentionPolicy::ACTION_PURGE,
            'trigger_kind' => RetentionPolicy::TRIGGER_AGE_BASED,
            'trigger_value' => 'P7D',
        ]);

        $job = new HoldScanJob($this->entityTypeManager, $this->auditWriter);
        $job->run();

        // The held entity must NOT be deleted — hold-scan is verification only.
        self::assertNotNull(
            $this->loadByUuid($repository, 'held-and-purgeable'),
            'T-R hold-scan: HoldScanJob must never delete data.',
        );

        $conflicts = array_values(array_filter(
            $this->eventsOfKind(AuditEventKind::ClassificationChange),
            static fn(AuditEventDescriptor $d): bool => ($d->attributes['conflict'] ?? null) === 'hold_vs_purge',
        ));
        self::assertNotEmpty(
            $conflicts,
            "T-R hold-scan: a classification.change event with conflict='hold_vs_purge' must be written.",
        );
    }

    // ------------------------------------------------------------------
    // Kernel boot + composition helpers
    // ------------------------------------------------------------------

    private function bootKernel(string $projectRoot, AuditWriterInterface $auditWriter): AbstractKernel
    {
        $fieldMigrations = \dirname(__DIR__, 3) . '/packages/field/migrations';

        $kernel = new class ($projectRoot, $auditWriter, $fieldMigrations) extends AbstractKernel {
            public function __construct(
                string $projectRoot,
                private readonly AuditWriterInterface $auditWriterBinding,
                private readonly string $fieldMigrationsDir,
            ) {
                parent::__construct($projectRoot);
            }

            public function publicBoot(): void
            {
                $this->boot();
            }

            protected function compileManifest(): void
            {
                $this->manifest = new PackageManifest(
                    providers: [
                        FieldServiceProvider::class,
                        Fr015TestServiceProvider::class,
                    ],
                    migrations: [
                        // Absolute dir is honoured verbatim by MigrationLoader
                        // (resolvePackageMigrationDirectory: is_dir($path) → use it).
                        'waaseyaa/field' => $this->fieldMigrationsDir,
                    ],
                    policies: [
                        // Wildcard registration mirrors the production manifest
                        // produced from #[PolicyAttribute(entityType: '*')].
                        ClassificationFieldAccessPolicy::class => ['*'],
                    ],
                );
            }

            protected function discoverAndRegisterProviders(): void
            {
                parent::discoverAndRegisterProviders();

                // The Fr015 test provider needs the recording AuditWriter binding
                // injected so both the lifecycle subscriber and the retention jobs
                // share it. Providers are instantiated by the parent; reach in and
                // hand the instance its dependency.
                foreach ($this->providers as $provider) {
                    if ($provider instanceof Fr015TestServiceProvider) {
                        $provider->setAuditWriter($this->auditWriterBinding);
                    }
                }
            }
        };

        $kernel->publicBoot();

        return $kernel;
    }

    /** @return object{recorded: list<AuditEventDescriptor>}&AuditWriterInterface */
    private function recordingAuditWriter(): AuditWriterInterface
    {
        return new class implements AuditWriterInterface {
            /** @var list<AuditEventDescriptor> */
            public array $recorded = [];

            public function record(AuditEventDescriptor $descriptor): void
            {
                $this->recorded[] = $descriptor;
            }
        };
    }

    /**
     * @return list<AuditEventDescriptor>
     */
    private function eventsOfKind(AuditEventKind $kind): array
    {
        /** @var object{recorded: list<AuditEventDescriptor>} $writer */
        $writer = $this->auditWriter;

        return array_values(array_filter(
            $writer->recorded,
            static fn(AuditEventDescriptor $d): bool => $d->kind === $kind,
        ));
    }

    /**
     * Resolve the kernel-wired LabelInheritanceResolver singleton (carrying the
     * production parent resolvers + the fr015_document resolver registered by
     * the test provider) — the same instance the lifecycle subscriber uses.
     */
    private function labelInheritanceResolver(): LabelInheritanceResolver
    {
        foreach ($this->kernel->getProviders() as $provider) {
            if (isset($provider->getBindings()[LabelInheritanceResolver::class])) {
                $resolved = $provider->resolve(LabelInheritanceResolver::class);
                self::assertInstanceOf(LabelInheritanceResolver::class, $resolved);

                return $resolved;
            }
        }

        self::fail('LabelInheritanceResolver is not bound by any booted provider.');
    }

    private function fieldAccess(ContentEntityBase $entity, AccountInterface $account): \Waaseyaa\Access\AccessResult
    {
        // Resolve the field-access decision through the REAL kernel-discovered
        // access handler — not a hand-constructed policy. This is what makes the
        // hold-block guard load-bearing (see the guard block above).
        return $this->kernel->getAccessHandler()->checkFieldAccess($entity, 'body', 'view', $account);
    }

    private function seedLabels(): void
    {
        $repository = $this->entityTypeManager->getRepository('classification_label_definition');
        // confidential is seeded at level 10 so a default `admin` (clearance 10)
        // is Neutral while anonymous (0) is Forbidden — T-R (c).
        $seed = [
            ['uuid' => 'lbl-public', 'label_id' => 'public', 'display_name' => 'Public', 'confidentiality_level' => 0],
            ['uuid' => 'lbl-conf', 'label_id' => 'confidential', 'display_name' => 'Confidential', 'confidentiality_level' => 10],
            ['uuid' => 'lbl-hold', 'label_id' => 'hold-legal', 'display_name' => 'Hold — Legal', 'confidentiality_level' => 60],
        ];
        foreach ($seed as $values) {
            $label = new ClassificationLabelDefinition($values);
            $label->enforceIsNew();
            $repository->save($label, validate: false);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function seedPolicy(array $values): void
    {
        $repository = $this->entityTypeManager->getRepository('retention_policy');
        $policy = $repository->create($values);
        $repository->save($policy, validate: false);
    }

    private function loadPolicyIdByUuid(string $uuid): int|string|null
    {
        $policy = $this->loadByUuid($this->entityTypeManager->getRepository('retention_policy'), $uuid);

        return $policy?->id();
    }

    /**
     * C-22 WP4: `EntityRepository` has no `loadByKey()` equivalent (the
     * legacy `SqlEntityStorage` method is retired — see
     * `packages/entity-storage/tests/Unit/EntityRepositorySqlCrudTest.php`
     * docblock). Production callsites were converted to a bounded
     * `getQuery()->condition(...)->range(0, 1)->execute()` + `find()` chain;
     * this test helper mirrors that pattern. `accessCheck(false)` is an
     * explicit opt-out: these fixtures run in a system-context test harness
     * with no `AccountInterface` bound to the query.
     */
    private function loadByUuid(EntityRepositoryInterface $repository, string $uuid): ?EntityInterface
    {
        $ids = $repository->getQuery()->accessCheck(false)->condition('uuid', $uuid)->range(0, 1)->execute();
        $id = $ids[0] ?? null;

        return $id === null ? null : $repository->find((string) $id);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function newEntity(array $values): Fr015Document
    {
        $entity = new Fr015Document($values);
        $entity->enforceIsNew();

        return $entity;
    }

    private function anonymous(): AccountInterface
    {
        return $this->account(['anonymous'], []);
    }

    private function admin(bool $bypass): AccountInterface
    {
        return $this->account(['admin'], $bypass ? [Permissions::LEGAL_HOLD_BYPASS] : []);
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function account(array $roles, array $permissions): AccountInterface
    {
        return new class ($roles, $permissions) implements AccountInterface {
            /**
             * @param list<string> $roles
             * @param list<string> $permissions
             */
            public function __construct(
                private readonly array $roles,
                private readonly array $permissions,
            ) {}

            public function id(): int
            {
                return in_array('anonymous', $this->roles, true) ? 0 : 1;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            /** @return list<string> */
            public function getRoles(): array
            {
                return $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return !in_array('anonymous', $this->roles, true);
            }
        };
    }
}

/**
 * Participant entity for the FR-015 scenario. Carries the classification
 * columns in its `_data` blob so the retention jobs'
 * `getQuery()->exists('classification_label')->condition('created_at', …)`
 * chains resolve against `json_extract(_data, …)`.
 *
 * @api
 */
#[ContentEntityType(
    id: 'fr015_document',
    label: 'FR-015 Test Document',
    description: 'Integration-test participant carrying classification fields.',
)]
final class Fr015Document extends ContentEntityBase
{
    #[Field(type: 'string', required: false, read: FieldReadLevel::Public)]
    public string $parent_uuid;

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @param array<string, mixed> $fieldDefinitions
     */
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
 * Test-only service provider that registers the FR-015 participant entity type
 * and binds the recording AuditWriter so the booted classification pipeline
 * (lifecycle subscriber) and the retention jobs share one event sink.
 *
 * @api
 */
final class Fr015TestServiceProvider extends ServiceProvider
{
    private ?AuditWriterInterface $auditWriter = null;

    public function setAuditWriter(AuditWriterInterface $auditWriter): void
    {
        $this->auditWriter = $auditWriter;
        // Re-bind now that the instance is available (register() ran earlier).
        $this->singleton(AuditWriterInterface::class, fn(): AuditWriterInterface => $auditWriter);
    }

    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'fr015_document',
            label: 'FR-015 Test Document',
            class: Fr015Document::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            description: 'Integration-test participant carrying classification fields.',
        ));

        // Provide a placeholder binding so resolveOptional() succeeds during
        // register()/boot ordering; replaced with the recording writer via
        // setAuditWriter() once the kernel hands us the instance.
        $this->singleton(
            AuditWriterInterface::class,
            fn(): AuditWriterInterface => $this->auditWriter ?? new class implements AuditWriterInterface {
                public function record(AuditEventDescriptor $descriptor): void {}
            },
        );
    }

    public function boot(): void
    {
        // Register a parent resolver for the participant type on the shared
        // LabelInheritanceResolver singleton (bound by FieldServiceProvider).
        // The same instance is handed to the lifecycle subscriber at boot, so
        // inheritance resolves for fr015_document children that carry a
        // `parent_uuid` pointer.
        $resolver = $this->resolveOptional(LabelInheritanceResolver::class);
        if (!$resolver instanceof LabelInheritanceResolver) {
            return;
        }

        $entityTypeManager = $this->resolveOptional(EntityTypeManager::class);
        if (!$entityTypeManager instanceof EntityTypeManager) {
            return;
        }

        $resolver->addResolver(new class ($entityTypeManager) implements ClassificationParentResolverInterface {
            public function __construct(private readonly EntityTypeManager $entityTypeManager) {}

            public function getSupportedEntityTypeId(): string
            {
                return 'fr015_document';
            }

            public function resolveParent(EntityInterface $entity): ?EntityInterface
            {
                $parentUuid = $entity->get('parent_uuid');
                if (!is_string($parentUuid) || $parentUuid === '') {
                    return null;
                }

                $repository = $this->entityTypeManager->getRepository('fr015_document');
                $ids = $repository->getQuery()->accessCheck(false)->condition('uuid', $parentUuid)->range(0, 1)->execute();
                $id = $ids[0] ?? null;

                return $id === null ? null : $repository->find((string) $id);
            }
        });

        // NOTE (WP4 dead-subscriber sweep, audit-remediation batch
        // 2026-07-01/02): this method used to ALSO hand-attach a second
        // EntityLifecycleSubscriber to the dispatcher here, because
        // FieldServiceProvider::boot() resolved the dispatcher under the
        // foundation EventDispatcherInterface FQCN — a key the kernel-services
        // bus never serves — so its own subscriber wiring silently no-op'd in
        // every real kernel boot, including this test's. That production bug
        // is now fixed (FieldServiceProvider::boot() resolves the served
        // Symfony-contracts FQCN, same #1852 pattern), so this provider's
        // boot() only needs to register the entity-type-specific parent
        // resolver on the SHARED LabelInheritanceResolver singleton above —
        // FieldServiceProvider's own subscriber (bound to that same resolver
        // instance) now runs end-to-end without help. Re-adding a duplicate
        // subscriber here would double-run inheritance resolution per save:
        // the second pass sees the entity already carrying the label the
        // first pass just set, treats it as an explicit override, and wipes
        // `classification_inherited_from` back to null.
    }
}

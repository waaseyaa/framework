<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * REQUIRED wiring regression test (CW-v1 WP-1, docs/specs/content-workflow.md
 * "Wiring invariants"): a unit test on {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard}
 * alone does NOT prove the save-path guard is wired — only a real
 * kernel-dispatched save through the real {@see EntityRepository}, with
 * {@see WorkflowServiceProvider::boot()} doing the ACTUAL listener
 * registration on the SAME dispatcher instance the repository uses, proves
 * the wiring. Every collaborator here (dispatcher, EntityTypeManager,
 * ConfigFactory) is the real production class — nothing about the guard
 * itself is mocked or invoked directly.
 *
 * SCOPE — what this proves and what it cannot yet (issue #1930): the wired
 * tests below prove boot() registers the guard correctly ONCE
 * `ConfigFactoryInterface` is served by the kernel-services bus — the stub
 * bus here serves it; the REAL production bus does not yet, so in a real
 * boot today the provider takes the degraded path instead (no guard, no
 * seed, one logged warning). That degradation is itself pinned by
 * {@see self::boot_degrades_safely_and_loudly_without_a_config_factory()}.
 * The root fix — binding ConfigFactoryInterface in the kernel — is a
 * separate in-flight PR anchored by #1930; when it lands, the wired path
 * proven here becomes the live production path with no further change to
 * this package.
 *
 * Mirrors `RelationshipServiceProviderTest::boot_wires_delete_guard_to_pre_delete_event`
 * (unit-level: asserts a listener got registered) combined with
 * `RevisionAuthorTest::buildRepository()` (integration-level: a real
 * SqlSchemaHandler + SqlStorageDriver + RevisionableStorageDriver stack over
 * real SQLite) — this test is the union: the SAME dispatcher instance is
 * fed to both `WorkflowServiceProvider::boot()`'s `addListener()` call and
 * the `EntityRepository` that performs the save, so a real save fires the
 * REAL guard, not a stand-in.
 */
#[CoversNothing]
final class GuardWiringTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'guard_wiring_subject';

    #[Test]
    public function a_real_kernel_dispatched_save_fires_the_guard_and_floors_a_permissionless_born_published_create(): void
    {
        [$entityTypeManager] = $this->bootWiredProvider();

        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $entity = new GuardWiringSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'published'],
            self::ENTITY_TYPE_ID,
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        );

        $thrown = null;
        try {
            $repository->save($entity);
        } catch (TransitionDeniedException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            TransitionDeniedException::class,
            $thrown,
            'A real kernel-dispatched save must prove the guard fires (wiring regression, not a guard unit test).',
        );
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $thrown->reason);
    }

    #[Test]
    public function a_real_kernel_dispatched_create_without_workflow_state_is_forced_to_the_initial_state(): void
    {
        [$entityTypeManager] = $this->bootWiredProvider();

        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $entity = new GuardWiringSubject(
            ['bundle' => self::ENTITY_TYPE_ID],
            self::ENTITY_TYPE_ID,
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        );

        $repository->save($entity);

        $stored = $repository->find((string) $entity->id());
        $this->assertNotNull($stored);
        $this->assertSame('draft', $stored->get('workflow_state'));
        $this->assertSame(0, $stored->get('status'));
    }

    /**
     * WP-2 task 2.5 companion to the save-path proofs above: a real
     * kernel-dispatched `setPublishedRevision()` — NOT a `save()` — must fire
     * {@see \Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard} through the
     * SAME dispatcher `WorkflowServiceProvider::boot()` wires it onto. Moves
     * from the (never-published) initial state 'draft' to the target
     * revision's 'published' state — a legal `publish` edge — and must
     * actually move the base-row published pointer, proving the guard does
     * not merely run but lets a legitimate pointer move through.
     */
    #[Test]
    public function a_real_kernel_dispatched_publish_pointer_move_fires_the_pointer_guard_and_allows_a_legal_edge(): void
    {
        [$entityTypeManager] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        $entity = new GuardWiringSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'published'],
            self::ENTITY_TYPE_ID,
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        );
        // Null account context: the save-path guard's create rule denies a
        // born-published create without permission (REASON_PERMISSION), so
        // create as 'draft' first, then let a subsequent save reach
        // 'published' via the (permission-blind, edge-only) update rule.
        $entity->set('workflow_state', 'draft');
        $repository->save($entity);

        $draft = $repository->find((string) $entity->id());
        $this->assertNotNull($draft);
        // This fixture entity type has no revisionDefault: true (that is a
        // node-specific WP-2 task 2.1/2.3 decision) — force a new revision so
        // 'draft' (revision 1) and 'published' (revision 2) are distinct rows,
        // matching how forward-draft editing actually behaves on node.
        $draft->setNewRevision(true);
        $draft->set('workflow_state', 'published');
        $repository->save($draft);
        // save() updates the `revision_id` field value directly (not the
        // RevisionableEntityTrait's own revisionId() property, which only
        // loadRevision() populates) — read the field.
        $publishedRevisionId = (int) $draft->get('revision_id');

        // The base row has never had a published pointer — the pointer
        // guard's "currently effective state" for `publish` falls back to the
        // workflow's initial state ('draft'); 'draft' -> 'published' is a
        // legal `publish` edge, so this must succeed and move the pointer.
        $repository->setPublishedRevision((string) $entity->id(), $publishedRevisionId);

        $published = $repository->loadPublishedRevision((string) $entity->id());
        $this->assertNotNull($published);
        $this->assertSame($publishedRevisionId, $published->revisionId());
    }

    /**
     * WP-2 task 2.5: a real kernel-dispatched `setCurrentRevision()` moving
     * across an edge the bound workflow does not define ('published' ->
     * 'review' has no direct transition in the default editorial workflow)
     * must be denied by the SAME wired guard, proving the pointer-move bypass
     * is actually closed end to end — not merely in a guard-only unit test.
     * 'review' is used as the illegal target (rather than 'draft') because
     * its only incoming edge is 'submit_for_review' from 'draft'.
     */
    #[Test]
    public function a_real_kernel_dispatched_revert_pointer_move_fires_the_pointer_guard_and_denies_an_illegal_edge(): void
    {
        [$entityTypeManager] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        $entity = new GuardWiringSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'draft'],
            self::ENTITY_TYPE_ID,
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
        );
        $repository->save($entity);

        // Walk the entity draft -> review -> published, one new revision per
        // hop (all legal edges; null account context checks edge-legality
        // only), so a 'review'-stamped revision exists distinct from the
        // 'published' revision that becomes current.
        $draft = $repository->find((string) $entity->id());
        $this->assertNotNull($draft);
        $draft->setNewRevision(true);
        $draft->set('workflow_state', 'review');
        $repository->save($draft);
        $reviewRevisionId = (int) $draft->get('revision_id');

        $review = $repository->find((string) $entity->id());
        $this->assertNotNull($review);
        $review->setNewRevision(true);
        $review->set('workflow_state', 'published');
        $repository->save($review);

        $thrown = null;
        try {
            // Reverting the current pointer from 'published' back to the
            // earlier 'review' revision has no edge in the editorial
            // workflow ('review' is only reachable via 'submit_for_review'
            // from 'draft') — must be denied before any write.
            $repository->setCurrentRevision((string) $entity->id(), $reviewRevisionId);
        } catch (TransitionDeniedException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            TransitionDeniedException::class,
            $thrown,
            'A real kernel-dispatched pointer move must prove the pointer guard fires (wiring regression, not a guard unit test).',
        );
        $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $thrown->reason);
    }

    /**
     * Companion to the wired tests above — pins the DEGRADED boot path
     * (review amendment, issue #1930): when the kernel-services bus does NOT
     * serve `ConfigFactoryInterface` (the real production bus today),
     * boot() must complete without throwing, register NO PRE_SAVE listener,
     * seed NOTHING, and log exactly one warning naming #1930. This makes the
     * current production no-op INTENTIONAL, PINNED, and VISIBLE behavior
     * rather than an accident — silent inertness is how the predecessor
     * engine died.
     */
    #[Test]
    public function boot_degrades_safely_and_loudly_without_a_config_factory(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
            $schemaHandler = new SqlSchemaHandler($definition, $db);
            $schemaHandler->ensureTable();

            return new EntityRepository(
                $definition,
                new SqlStorageDriver(new SingleConnectionResolver($db)),
                $dispatcher,
                null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workflow',
            label: 'Workflow',
            class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'workflows',
        ));

        $logger = new GuardWiringSpyLogger();

        // The stub bus mirrors ProviderRegistryKernelServices as it exists in
        // production TODAY: dispatcher, EntityTypeManager, and logger are
        // served; ConfigFactoryInterface is NOT (issue #1930).
        $kernelServices = new class ($dispatcher, $entityTypeManager, $logger) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly LoggerInterface $logger,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    LoggerInterface::class => $this->logger,
                    default => null,
                };
            }
        };

        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices($kernelServices);
        $provider->register();

        // 1. SAFE: boot() completes without throwing.
        $provider->boot();

        // 2. No PRE_SAVE listener was registered.
        $this->assertSame(
            [],
            $dispatcher->getListeners(EntityEvents::PRE_SAVE->value),
            'Degraded boot must not register the save-path guard.',
        );

        // 2b. No BeforeRevisionPointerMoveEvent listener was registered
        // either (WP-2 task 2.5): the pointer guard shares the same
        // ConfigFactoryInterface dependency chain.
        $this->assertSame(
            [],
            $dispatcher->getListeners(BeforeRevisionPointerMoveEvent::class),
            'Degraded boot must not register the pointer-move guard.',
        );

        // 3. Nothing was seeded.
        $this->assertNull(
            $entityTypeManager->getRepository('workflow')->find('editorial'),
            'Degraded boot must not seed the default editorial workflow.',
        );

        // 4. LOUD: exactly one warning, naming issue #1930.
        $this->assertCount(1, $logger->warnings, 'Degraded boot must log exactly one warning.');
        $this->assertSame('workflows.engine_not_wired', $logger->warnings[0]['message']);
        $this->assertStringContainsString('1930', (string) json_encode($logger->warnings[0]['context']));
    }

    /**
     * Wires a real dispatcher, a real SQLite-backed EntityTypeManager (both
     * the `workflow` config entity type and the revisionable fixture content
     * entity type), a real ConfigFactory with the fixture bound to the
     * seeded `editorial` workflow, then boots a REAL WorkflowServiceProvider
     * against a stub kernel-services bus that serves all three under the
     * exact FQCNs production code resolves them by.
     *
     * @return array{0: EntityTypeManager}
     */
    private function bootWiredProvider(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => 'editorial',
        ]);
        $configFactory = new ConfigFactory($configStorage, $dispatcher);

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
            $schemaHandler = new SqlSchemaHandler($definition, $db);
            $schemaHandler->ensureTable();
            if ($definition->isRevisionable()) {
                $schemaHandler->ensureRevisionTable();
            }

            $resolver = new SingleConnectionResolver($db);

            return new EntityRepository(
                $definition,
                new SqlStorageDriver($resolver),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workflow',
            label: 'Workflow',
            class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'workflows',
        ));

        $entityTypeManager->registerEntityType(new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Guard wiring subject',
            class: GuardWiringSubject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        ));

        $kernelServices = new class ($dispatcher, $entityTypeManager, $configFactory) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly ConfigFactoryInterface $configFactory,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    ConfigFactoryInterface::class => $this->configFactory,
                    default => null,
                };
            }
        };

        // The subject under test: the REAL provider, booted against the REAL
        // kernel-services bus. boot() both wires WorkflowStateGuard onto
        // $dispatcher (the SAME instance the repositoryFactory above dispatches
        // PRE_SAVE through) and seeds the default `editorial` workflow.
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices($kernelServices);
        $provider->register();
        $provider->boot();

        return [$entityTypeManager];
    }
}

final class GuardWiringSpyLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    public function emergency(string|\Stringable $message, array $context = []): void {}

    public function alert(string|\Stringable $message, array $context = []): void {}

    public function critical(string|\Stringable $message, array $context = []): void {}

    public function error(string|\Stringable $message, array $context = []): void {}

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = ['message' => (string) $message, 'context' => $context];
    }

    public function notice(string|\Stringable $message, array $context = []): void {}

    public function info(string|\Stringable $message, array $context = []): void {}

    public function debug(string|\Stringable $message, array $context = []): void {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void {}
}

final class GuardWiringSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
{
    use RevisionableEntityTrait;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

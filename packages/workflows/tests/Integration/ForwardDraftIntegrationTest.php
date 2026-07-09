<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
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
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * Required integration test (CW-v1 WP-2 task 2.6, #1920,
 * docs/specs/content-workflow.md "forward draft" / "two-pointer status
 * semantics"): the forward-draft flow end-to-end, through the REAL kernel
 * wiring — real dispatcher, real SQLite-backed `EntityRepository`, a REAL
 * `WorkflowServiceProvider::boot()` (proving `WorkflowStateGuard` AND
 * `WorkflowPointerMoveGuard` are both live on the same dispatcher the
 * repository saves through, and that the SHIPPED `editorial` workflow —
 * seeded by boot() itself from `DefaultWorkflows::EDITORIAL`, including the
 * `revise` published -> draft forward-draft entry edge — carries the whole
 * story with no synthetic edges), mirroring {@see GuardWiringTest}'s wiring
 * style. An ambient account context ({@see RequestAccountContext}) is served
 * through the kernel-services bus so both guards run their permission checks
 * for real.
 *
 * Scenario (task 2.6 brief, verbatim): publish a node -> edit via raw save
 * into 'draft' (forward draft) -> assert the public read path (published
 * pointer + status) still serves the OLD content and status=1 -> transition
 * the draft revision to 'published' -> assert the pointer moved and the new
 * content is live. Plus the denial leg: an account holding no
 * transition-into-'published' permission attempting a direct pointer
 * promotion is denied by the wired pointer guard, leaving BOTH the persisted
 * `status` and `published_revision_id` unchanged.
 *
 * Uses a synthetic revisionable entity type rather than real `Node`
 * deliberately: the flow under test is engine mechanics (workflows L3 +
 * entity-storage L1); pulling in `node` would add its bundle/NodeType
 * listener surface without exercising any additional task-2.6 code path
 * (Node's revision wiring is Tasks 2.1–2.3's coverage, and the full-stack
 * node spine is Task 2.8's).
 */
#[CoversNothing]
final class ForwardDraftIntegrationTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'forward_draft_subject';

    #[Test]
    public function forward_draft_on_published_content_leaves_the_live_version_serving_until_republished(): void
    {
        [$entityTypeManager, $provider, $accountContext] = $this->bootWiredProvider();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(7, ['use editorial transition publish', 'use editorial transition revise']);
        $accountContext->set($editor);

        // --- 1. Create + publish (on the SEEDED editorial workflow). ---
        $entity = new ForwardDraftSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'draft', 'title' => 'Original title'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($entity);
        $entityId = (string) $entity->id();

        $transitionService->transition($entity, 'publish', $editor);

        $publishedRevisionId = (int) $entity->get('revision_id');
        $publishedPointer = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($publishedPointer);
        $this->assertSame($publishedRevisionId, (int) $publishedPointer->get('revision_id'));
        $this->assertSame('Original title', $publishedPointer->get('title'));
        $this->assertSame(1, $publishedPointer->get('status'));

        // --- 2. Raw-save forward draft (NOT through TransitionService): ---
        // edit the current tip with new content, moving ITS OWN
        // workflow_state to 'draft' via the shipped 'revise' edge
        // (published -> draft; the acting account holds its permission).
        // WorkflowStateGuard fires on this save (task 2.6): it must NOT
        // flip status to 'draft'.published (false => 0) since a published
        // pointer already exists.
        $tip = $repository->find($entityId);
        $this->assertNotNull($tip);
        $tip->setNewRevision(true);
        $tip->set('title', 'Forward draft title');
        $tip->set('workflow_state', 'draft');
        $repository->save($tip);
        $draftRevisionId = (int) $tip->get('revision_id');
        $this->assertNotSame($publishedRevisionId, $draftRevisionId);

        // --- 3. Public read path: published pointer + status must still ---
        // serve the OLD content, completely untouched by the forward draft.
        $stillLive = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($stillLive);
        $this->assertSame($publishedRevisionId, (int) $stillLive->get('revision_id'));
        $this->assertSame('Original title', $stillLive->get('title'));
        $this->assertSame(1, $stillLive->get('status'));

        // The current/tip row (what an editor sees) is the new draft, and
        // its own `status` was preserved (copied from the published
        // pointer), not flipped to the 'draft' state's published flag (0).
        $currentTip = $repository->find($entityId);
        $this->assertNotNull($currentTip);
        $this->assertSame('draft', $currentTip->get('workflow_state'));
        $this->assertSame('Forward draft title', $currentTip->get('title'));
        $this->assertSame(1, $currentTip->get('status'));

        // --- 4. Publish the draft revision through TransitionService: ---
        // the pointer must move and the new content becomes live. The wired
        // pointer guard sees a SAME-state move (published -> published: the
        // service saves the 'published' tip first, then moves the pointer)
        // and allows it because the acting account holds 'publish' — a
        // transition into 'published'.
        $transitionService->transition($currentTip, 'publish', $editor);

        $newlyLive = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($newlyLive);
        $this->assertSame('Forward draft title', $newlyLive->get('title'));
        $this->assertSame(1, $newlyLive->get('status'));
        $newLiveRevisionId = (int) $newlyLive->get('revision_id');
        $this->assertNotSame($publishedRevisionId, $newLiveRevisionId);

        // --- 5. Denial leg (real guard, real DB): an account holding NO ---
        // transition-into-'published' permission attempts a direct pointer
        // promotion (resurrecting the OLD published revision — a same-state
        // published -> published move). The wired WorkflowPointerMoveGuard
        // must deny it BEFORE any write: both the persisted published
        // pointer AND the base row's status must be unchanged afterward.
        $accountContext->set($this->account(8, []));

        $denied = null;
        try {
            $repository->setPublishedRevision($entityId, $publishedRevisionId);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        $afterDenial = $repository->loadPublishedRevision($entityId);
        $this->assertNotNull($afterDenial);
        $this->assertSame($newLiveRevisionId, (int) $afterDenial->get('revision_id'), 'Denied pointer move must leave published_revision_id unchanged.');
        $this->assertSame('Forward draft title', $afterDenial->get('title'));
        $baseRow = $repository->find($entityId);
        $this->assertNotNull($baseRow);
        $this->assertSame(1, $baseRow->get('status'), 'Denied pointer move must leave the persisted status unchanged.');
    }

    /**
     * @return array<string, string>
     */
    private function entityKeys(): array
    {
        return ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];
    }

    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
        };
    }

    /**
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext}
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
            label: 'Forward draft subject',
            class: ForwardDraftSubject::class,
            keys: $this->entityKeys(),
            revisionable: true,
        ));

        $accountContext = new RequestAccountContext();

        $kernelServices = new class ($dispatcher, $entityTypeManager, $configFactory, $accountContext) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly ConfigFactoryInterface $configFactory,
                private readonly AccountContextInterface $accountContext,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    ConfigFactoryInterface::class => $this->configFactory,
                    AccountContextInterface::class => $this->accountContext,
                    default => null,
                };
            }
        };

        // The subject under test: the REAL provider, booted against the REAL
        // kernel-services bus. boot() wires BOTH WorkflowStateGuard (task
        // 2.6's forward-draft status rule) and WorkflowPointerMoveGuard
        // (task 2.5/2.6's pointer-move validation) onto $dispatcher — the
        // SAME instance the repositoryFactory above dispatches through —
        // and seeds the SHIPPED `editorial` workflow
        // (DefaultWorkflows::EDITORIAL, including the `revise` edge).
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices($kernelServices);
        $provider->register();
        $provider->boot();

        return [$entityTypeManager, $provider, $accountContext];
    }
}

final class ForwardDraftSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
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

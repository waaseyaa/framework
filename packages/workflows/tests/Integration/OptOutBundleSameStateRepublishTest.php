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
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * Fix-wave regression tests (#1920 PR-2 adversarial review): the same-state
 * republish gate must apply to EVERY disciplined same-state save of a
 * `default_revision: true` state — revision-creating or NOT.
 *
 * The reviewed hole: `guardSameStateRepublish()` gated only when
 * `willCreateRevision()` was true. On a `new_revision: false` NodeType bound
 * to a workflow, `NodeRevisionDefaultListener` (also PRE_SAVE) sets
 * `isNewRevision(false)`; in the node-listener-first order (the spine's own
 * wiring order and the likely production order) the gate returned early
 * BEFORE the any-of authorization, the save updated the published revision
 * in place, and the storage `writeBase` rule put UNAUTHORIZED content live —
 * an account holding plain entity update access edited live published
 * content with no workflow permission checked. In the guard-first order the
 * guard instead armed spuriously (the revision was never created), and the
 * POST_SAVE listener promoted the unchanged pointer — a redundant pointer
 * event / audit entry / reindex.
 *
 * Both listener registration orders are exercised: node-listener-first
 * (NodeServiceProvider::boot() before WorkflowServiceProvider::boot(), the
 * spine's order) and guard-first (the reverse).
 */
#[CoversNothing]
final class OptOutBundleSameStateRepublishTest extends TestCase
{
    #[Test]
    public function node_listener_first_an_unauthorized_same_state_save_on_an_opt_out_bundle_is_denied_and_the_base_row_is_unchanged(): void
    {
        $this->runUnauthorizedDenialScenario(nodeListenerFirst: true);
    }

    #[Test]
    public function guard_first_an_unauthorized_same_state_save_on_an_opt_out_bundle_is_denied_and_the_base_row_is_unchanged(): void
    {
        $this->runUnauthorizedDenialScenario(nodeListenerFirst: false);
    }

    #[Test]
    public function node_listener_first_an_authorized_same_state_save_on_an_opt_out_bundle_updates_the_served_row_in_place(): void
    {
        $this->runAuthorizedInPlaceScenario(nodeListenerFirst: true);
    }

    #[Test]
    public function guard_first_an_authorized_same_state_save_on_an_opt_out_bundle_updates_the_served_row_in_place_without_a_spurious_pointer_move(): void
    {
        // Test (d): in the guard-first order the guard evaluates
        // willCreateRevision() BEFORE NodeRevisionDefaultListener applies
        // the bundle opt-out, so it sees the entity-type default (true) and
        // arms — a spurious arm the POST_SAVE listener must render harmless
        // via its already-published self-skip: NO RevisionPointerMovedEvent
        // may be dispatched for an in-place same-state save.
        $this->runAuthorizedInPlaceScenario(nodeListenerFirst: false);
    }

    #[Test]
    public function a_stale_arm_from_a_pre_save_aborted_save_never_promotes_on_a_later_save_of_the_same_object(): void
    {
        // Test (c): an authorized same-state revision-creating save whose
        // PRE_SAVE pipeline is aborted AFTER the guard armed (a later
        // listener throws) must not leave an arm behind that a later,
        // state-CHANGING save of the same entity object consumes — the
        // stale arm would promote the state-changing save's new draft tip,
        // silently enacting a pointer move the raw save was never allowed
        // to make.
        [$entityTypeManager, $provider, $accountContext, $db, $dispatcher, $pointerMoveCounter] = $this->bootWiredProviders(
            nodeListenerFirst: true,
            newRevision: true, // ordinary revisioning bundle: the aborted save WOULD have created a revision, so the guard genuinely arms
        );
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(11, [
            'use editorial_forward transition publish',
            'use editorial_forward transition revise',
        ]);
        $accountContext->set($editor);

        // Create + publish.
        $node = new Node(['title' => 'Original', 'type' => 'article', 'slug' => 'stale-arm']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $transitionService->transition($created, 'publish', $editor);

        $pointerBefore = $this->rawBaseRow($db, $entityId);

        // Register an aborting listener AFTER the guard (appended last on
        // PRE_SAVE), gated on a flag so only the next save aborts.
        $abort = new class {
            public bool $enabled = false;

            public function onPreSave(EntityEvent $event): void
            {
                if ($this->enabled) {
                    throw new \RuntimeException('simulated post-guard PRE_SAVE abort');
                }
            }
        };
        $dispatcher->addListener(EntityEvents::PRE_SAVE->value, [$abort, 'onPreSave']);

        // Authorized same-state revision-creating save -> guard arms, then
        // the aborting listener throws: nothing commits.
        $abort->enabled = true;
        $sameStateEdit = $nodeRepository->find($entityId);
        $this->assertNotNull($sameStateEdit);
        \assert($sameStateEdit instanceof Node);
        $sameStateEdit->setTitle('Aborted edit');
        $aborted = null;
        try {
            $nodeRepository->save($sameStateEdit);
        } catch (\RuntimeException $e) {
            $aborted = $e;
        }
        $this->assertNotNull($aborted);
        $this->assertSame('simulated post-guard PRE_SAVE abort', $aborted->getMessage());
        $abort->enabled = false;

        $this->assertSame($pointerBefore, $this->rawBaseRow($db, $entityId), 'The aborted save must have committed nothing.');

        // A state-CHANGING save of the SAME object (published -> draft, the
        // revise edge): disciplined + revision-only; the pointer must NOT
        // move — in particular, the stale arm from the aborted save must
        // not promote this save's new draft tip.
        $pointerMoveCounter->count = 0;
        $sameStateEdit->set('workflow_state', 'draft');
        $sameStateEdit->setTitle('Forward draft after abort');
        $nodeRepository->save($sameStateEdit);

        $baseAfter = $this->rawBaseRow($db, $entityId);
        $this->assertSame($pointerBefore, $baseAfter, 'A stale arm must never promote a later state-changing save of the same object — the base row stays byte-identical.');
        $this->assertSame(0, $pointerMoveCounter->count, 'No pointer move may fire from a stale arm.');

        $workingCopy = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($workingCopy);
        $this->assertSame('draft', $workingCopy->get('workflow_state'));
        $this->assertSame('Forward draft after abort', $workingCopy->get('title'));
    }

    private function runUnauthorizedDenialScenario(bool $nodeListenerFirst): void
    {
        [$entityTypeManager, $provider, $accountContext, $db] = $this->bootWiredProviders($nodeListenerFirst, newRevision: false);
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(11, ['use editorial_forward transition publish']);
        $accountContext->set($editor);

        $node = new Node(['title' => 'Published original', 'type' => 'article', 'slug' => 'opt-out-denial']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $transitionService->transition($created, 'publish', $editor);

        $baseRowBefore = $this->rawBaseRow($db, $entityId);
        $this->assertSame('Published original', $baseRowBefore['title']);

        // An account with NO transition-into-published permission attempts
        // a plain same-state content save. On this new_revision: false
        // bundle the save would be IN-PLACE — before the fix wave, the gate
        // skipped non-revision-creating saves entirely in the
        // node-listener-first order and the edit went LIVE unauthorized.
        $unauthorized = $this->account(12, ['use editorial_forward transition archive']);
        $accountContext->set($unauthorized);

        $edit = $nodeRepository->find($entityId);
        $this->assertNotNull($edit);
        \assert($edit instanceof Node);
        $edit->setTitle('Unauthorized live edit');

        $denied = null;
        try {
            $nodeRepository->save($edit);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied, 'An unauthorized in-place same-state edit of served content must be denied at PRE_SAVE.');
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        $this->assertSame($baseRowBefore, $this->rawBaseRow($db, $entityId), 'The denied save must leave the base row BYTE-IDENTICAL (raw SQL proof).');
    }

    private function runAuthorizedInPlaceScenario(bool $nodeListenerFirst): void
    {
        [$entityTypeManager, $provider, $accountContext, $db, , $pointerMoveCounter] = $this->bootWiredProviders($nodeListenerFirst, newRevision: false);
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(11, ['use editorial_forward transition publish']);
        $accountContext->set($editor);

        $node = new Node(['title' => 'Published original', 'type' => 'article', 'slug' => 'opt-out-authorized']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $transitionService->transition($created, 'publish', $editor);

        $publishedRevisionId = (int) $this->rawBaseRow($db, $entityId)['published_revision_id'];

        // The SAME save shape, by an account holding the publish permission
        // (any-of into 'published'): passes the gate and goes live through
        // the sanctioned in-place path (the save updates the published
        // revision, and the storage writeBase rule reaches the base row) —
        // NO pointer move happens or is needed.
        $pointerMoveCounter->count = 0;
        $edit = $nodeRepository->find($entityId);
        $this->assertNotNull($edit);
        \assert($edit instanceof Node);
        $edit->setTitle('Authorized in-place edit');
        $nodeRepository->save($edit);

        $baseRowAfter = $this->rawBaseRow($db, $entityId);
        $this->assertSame('Authorized in-place edit', $baseRowAfter['title'], 'The authorized in-place edit must reach the served base row.');
        $this->assertSame($publishedRevisionId, (int) $baseRowAfter['published_revision_id'], 'The pointer itself must not move — the published revision was edited in place.');
        $this->assertSame($publishedRevisionId, (int) $baseRowAfter['revision_id']);
        $this->assertSame(0, $pointerMoveCounter->count, 'An in-place same-state save must NOT dispatch RevisionPointerMovedEvent — no spurious promotion (fix-wave test d).');
    }

    /**
     * @return array<string, mixed>
     */
    private function rawBaseRow(DBALDatabase $db, string $entityId): array
    {
        $row = $db->getConnection()->fetchAssociative('SELECT * FROM node WHERE nid = ?', [$entityId]);
        $this->assertIsArray($row, 'Base row must exist.');

        return $row;
    }

    /**
     * @param list<string> $permissions
     */
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
     * Same real-provider bootstrap as ForwardDraftFlowTest, parameterized on
     * (1) the PRE_SAVE listener registration order — node-listener-first
     * (the spine's/production's order) vs guard-first — and (2) the
     * NodeType's per-bundle `new_revision` knob (false = the opt-out bundle
     * at the heart of the reviewed finding).
     *
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext, 3: DBALDatabase, 4: SymfonyEventDispatcherAdapter, 5: OptOutRepublishPointerMoveCounter}
     */
    private function bootWiredProviders(bool $nodeListenerFirst, bool $newRevision): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'editorial_forward',
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
                new SqlStorageDriver($resolver, $definition->getKeys()['id']),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);
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

        $nodeProvider = new NodeServiceProvider();
        $nodeProvider->setKernelServices($kernelServices);
        $nodeProvider->register();

        $workflowProvider = new WorkflowServiceProvider();
        $workflowProvider->setKernelServices($kernelServices);
        $workflowProvider->register();

        foreach ($nodeProvider->getEntityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }
        foreach ($workflowProvider->getEntityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }

        $entityTypeManager->getRepository('workflow')->save($this->editorialForwardWorkflow());

        // THE parameter under test: PRE_SAVE listener registration order.
        if ($nodeListenerFirst) {
            $nodeProvider->boot();      // NodeRevisionDefaultListener first (spine order)
            $workflowProvider->boot();  // WorkflowStateGuard second
        } else {
            $workflowProvider->boot();  // WorkflowStateGuard first
            $nodeProvider->boot();      // NodeRevisionDefaultListener second
        }

        // Pointer-move observation (fix-wave test d): count every
        // RevisionPointerMovedEvent dispatched.
        $pointerMoveCounter = new OptOutRepublishPointerMoveCounter();
        $dispatcher->addListener(RevisionPointerMovedEvent::class, [$pointerMoveCounter, 'onPointerMoved']);

        // The per-bundle knob at the heart of the finding.
        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article', 'new_revision' => $newRevision]));

        return [$entityTypeManager, $workflowProvider, $accountContext, $db, $dispatcher, $pointerMoveCounter];
    }

    /**
     * @see ForwardDraftFlowTest::editorialForwardWorkflow() — same test-local
     *   workflow (the descoped shipped shape plus a `revise` edge).
     */
    private function editorialForwardWorkflow(): Workflow
    {
        $transitions = DefaultWorkflows::EDITORIAL['transitions'];
        $transitions['revise'] = ['label' => 'Revise', 'from' => ['published'], 'to' => 'draft'];

        foreach ($transitions as $id => $transition) {
            $transition['permission'] = \sprintf('use editorial_forward transition %s', $id);
            $transitions[$id] = $transition;
        }

        $workflow = new Workflow([
            'id' => 'editorial_forward',
            'label' => 'Editorial (test-local, forward drafts)',
            'initial_state' => DefaultWorkflows::EDITORIAL['initial_state'],
            'states' => DefaultWorkflows::EDITORIAL['states'],
            'transitions' => $transitions,
        ]);
        $workflow->enforceIsNew();

        return $workflow;
    }
}

final class OptOutRepublishPointerMoveCounter
{
    public int $count = 0;

    public function onPointerMoved(RevisionPointerMovedEvent $event): void
    {
        ++$this->count;
    }
}

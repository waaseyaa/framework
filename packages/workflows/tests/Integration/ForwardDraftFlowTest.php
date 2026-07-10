<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\RollbackAuditListener;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
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
 * The WP-2 integration spine (CW-v1, docs/specs/content-workflow.md, "Testing
 * requirements — Integration spine"): the full editorial lifecycle on the
 * REAL `Node` entity type — not a synthetic fixture — bound to a test-local
 * `editorial_forward` workflow (the descoped shipped `EDITORIAL` shape plus a
 * `revise` published -> draft transition, persisted directly by this test),
 * over real SQLite, through the REAL {@see NodeServiceProvider} and
 * {@see WorkflowServiceProvider} wiring (both `NodeRevisionDefaultListener`
 * and both workflow guards live on the same dispatcher the repositories save
 * through). The shipped `editorial` workflow no longer ships a
 * published -> draft edge (WP-2 rework: forward drafts deferred, see
 * `DefaultWorkflows` and the package README) — this test proves the ENGINE
 * mechanics still hold via a workflow that does define one, not that the
 * shipped workflow exposes it.
 *
 * Story: create (guard forces draft, unpublished) -> publish (pointer +
 * status=1) -> forward-draft edit via `editorial_forward`'s `revise` edge
 * (live content untouched, status still 1, old content keeps serving via the
 * published pointer) -> submit for review -> publish the draft revision
 * (promotion: pointer moves, new content live) -> archive (pointer moves,
 * status=0) -> rollback attempt WITHOUT permission (denied via the pointer
 * guard, persisted state proven unchanged) -> rollback WITH permission
 * (succeeds and produces the rollback audit record via the real
 * {@see RollbackAuditListener}).
 *
 * Task 2.6's `ForwardDraftIntegrationTest` already proves the pointer/status
 * mechanics in isolation against a synthetic revisionable type; this spine's
 * job is different: prove the SAME wiring holds end-to-end against the real
 * node/node_type surface (bundle resolution via `NodeType`, the per-bundle
 * `new_revision` listener, real field storage) in one coherent story, per
 * the wp2-preamble instruction ("node is L2, workflows L3, require-dev
 * downward is fine").
 */
#[CoversNothing]
final class ForwardDraftFlowTest extends TestCase
{
    #[Test]
    public function full_editorial_lifecycle_on_a_real_node_through_a_test_local_forward_draft_workflow(): void
    {
        [$entityTypeManager, $provider, $accountContext, $auditWriter] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        $editor = $this->account(11, [
            'use editorial_forward transition publish',
            'use editorial_forward transition revise',
            'use editorial_forward transition submit_for_review',
            'use editorial_forward transition archive',
            'use editorial_forward transition restore_to_published',
        ]);
        $accountContext->set($editor);

        // --- 1. Create: the save-path guard forces initial_state + ---
        // unpublished, regardless of Node's own constructor default
        // (status defaults to 1 when not explicitly given).
        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $this->assertSame('draft', $created->get('workflow_state'));
        $this->assertSame(0, (int) $created->get('status'));
        $this->assertNull($nodeRepository->loadPublishedRevision($entityId), 'No published pointer exists before the first publish.');

        // --- 2. Publish: pointer established, status flips to 1. ---
        $result = $transitionService->transition($created, 'publish', $editor);
        $this->assertSame('published', $result->toState);

        $firstPublished = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($firstPublished);
        $firstPublishedRevisionId = (int) $firstPublished->get('revision_id');
        $this->assertSame('Original title', $firstPublished->get('title'));
        $this->assertSame(1, (int) $firstPublished->get('status'));
        $this->assertSame(1, (int) $nodeRepository->find($entityId)?->get('status'));

        // --- 3. Forward-draft edit via `editorial_forward`'s 'revise' edge ---
        // (published -> draft): the live content stays untouched, the new
        // tip is a non-default revision, and status keeps riding the
        // published pointer (decision 2) rather than following 'draft's
        // published:false flag.
        $tip = $nodeRepository->find($entityId);
        $this->assertNotNull($tip);
        \assert($tip instanceof Node);
        $tip->setTitle('Forward draft title');
        $reviseResult = $transitionService->transition($tip, 'revise', $editor);
        $this->assertSame('draft', $reviseResult->toState);

        $stillLive = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($stillLive);
        $this->assertSame($firstPublishedRevisionId, (int) $stillLive->get('revision_id'), 'Forward draft must not move the published pointer.');
        $this->assertSame('Original title', $stillLive->get('title'), 'Old content must keep serving via the published pointer.');
        $this->assertSame(1, (int) $stillLive->get('status'));
        $this->assertSame(1, (int) $nodeRepository->find($entityId)?->get('status'), 'Base-row status rides the pointer, not the tip.');

        $draftTip = $nodeRepository->find($entityId);
        $this->assertNotNull($draftTip);
        $this->assertSame('draft', $draftTip->get('workflow_state'));
        $this->assertSame('Forward draft title', $draftTip->get('title'));
        $this->assertNotSame($firstPublishedRevisionId, (int) $draftTip->get('revision_id'), 'The forward draft is a NEW, distinct revision.');

        // --- 4. Submit for review: another forward-draft save; pointer and ---
        // status remain untouched throughout.
        $submitResult = $transitionService->transition($draftTip, 'submit_for_review', $editor);
        $this->assertSame('review', $submitResult->toState);

        $reviewTip = $nodeRepository->find($entityId);
        $this->assertNotNull($reviewTip);
        $this->assertSame('review', $reviewTip->get('workflow_state'));
        $this->assertSame($firstPublishedRevisionId, (int) $nodeRepository->loadPublishedRevision($entityId)?->get('revision_id'));

        // --- 5. Publish the draft revision: promotion. The pointer moves ---
        // and the new content becomes live.
        $promoteResult = $transitionService->transition($reviewTip, 'publish', $editor);
        $this->assertSame('published', $promoteResult->toState);

        $promoted = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($promoted);
        $promotedRevisionId = (int) $promoted->get('revision_id');
        $this->assertNotSame($firstPublishedRevisionId, $promotedRevisionId, 'Promotion must move the pointer to a NEW revision.');
        $this->assertSame('Forward draft title', $promoted->get('title'));
        $this->assertSame(1, (int) $promoted->get('status'));
        $this->assertSame(1, (int) $nodeRepository->find($entityId)?->get('status'));

        // --- 6. Archive: pointer moves again, status flips to 0. ---
        $archiveSubject = $nodeRepository->find($entityId);
        $this->assertNotNull($archiveSubject);
        $archiveResult = $transitionService->transition($archiveSubject, 'archive', $editor);
        $this->assertSame('archived', $archiveResult->toState);

        $archived = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($archived);
        $archivedRevisionId = (int) $archived->get('revision_id');
        $this->assertSame('archived', $archived->get('workflow_state'));
        $this->assertSame(0, (int) $nodeRepository->find($entityId)?->get('status'));

        // --- 7. Rollback attempt WITHOUT permission: denied via the pointer ---
        // guard. archived -> published is a DIFFERENT-state pointer move
        // (the two states differ), so it needs the 'restore_to_published'
        // edge's own permission — holding 'publish'/'archive' alone is not
        // enough (that any-of rule only relaxes SAME-state moves).
        $restrictedEditor = $this->account(12, [
            'use editorial_forward transition publish',
            'use editorial_forward transition archive',
        ]);
        $accountContext->set($restrictedEditor);

        $denied = null;
        try {
            $nodeRepository->rollback($entityId, $firstPublishedRevisionId);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        // Persisted state must be completely unchanged by the denied attempt
        // (the pre-write choke point fires before any storage write).
        $afterDenial = $nodeRepository->find($entityId);
        $this->assertNotNull($afterDenial);
        $this->assertSame($archivedRevisionId, (int) $afterDenial->get('revision_id'));
        $this->assertSame('archived', $afterDenial->get('workflow_state'));
        $this->assertSame(0, (int) $afterDenial->get('status'));
        $afterDenialPointer = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($afterDenialPointer);
        $this->assertSame($archivedRevisionId, (int) $afterDenialPointer->get('revision_id'));

        $this->assertSame(
            0,
            $this->countRollbackRecords($auditWriter),
            'A denied rollback attempt must not produce a rollback audit record.',
        );

        // --- 8. Rollback WITH permission: succeeds and produces the ---
        // rollback audit record via the real RollbackAuditListener.
        $accountContext->set($editor);
        $rolledBack = $nodeRepository->rollback($entityId, $firstPublishedRevisionId);

        $this->assertSame('Original title', $rolledBack->get('title'));
        $this->assertSame('published', $rolledBack->get('workflow_state'));

        $newTip = $nodeRepository->find($entityId);
        $this->assertNotNull($newTip);
        $this->assertSame('Original title', $newTip->get('title'), 'The rollback must copy the old content forward as a NEW revision.');
        $this->assertNotSame($archivedRevisionId, (int) $newTip->get('revision_id'));

        $rollbackRecords = $this->rollbackRecords($auditWriter);
        $this->assertCount(1, $rollbackRecords, 'A successful rollback must produce exactly one rollback audit record.');
        $this->assertSame('allowed', $rollbackRecords[0]->outcome);
        $this->assertSame('node', $rollbackRecords[0]->entityTypeId);
        $this->assertSame($entityId, $rollbackRecords[0]->attributes['entity_id']);
        $this->assertSame($archivedRevisionId, $rollbackRecords[0]->attributes['from_revision_id']);
    }

    /**
     * @return list<AuditEventDescriptor>
     */
    private function rollbackRecords(ForwardDraftFlowSpyAuditWriter $auditWriter): array
    {
        return array_values(array_filter(
            $auditWriter->recorded,
            static fn(AuditEventDescriptor $d): bool => $d->kind === AuditEventKind::RevisionRollback,
        ));
    }

    private function countRollbackRecords(ForwardDraftFlowSpyAuditWriter $auditWriter): int
    {
        return count($this->rollbackRecords($auditWriter));
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
     * Wires real dispatcher + real SQLite-backed EntityTypeManager (node,
     * node_type, workflow), then boots the REAL NodeServiceProvider and the
     * REAL WorkflowServiceProvider against a stub kernel-services bus that
     * serves the collaborators both providers need under the exact FQCNs
     * production code resolves them by. The SAME dispatcher instance is fed
     * to both providers' `addListener()`/`addSubscriber()` calls and the
     * EntityRepository instances that perform every save/pointer-move in
     * this test, so the real listeners fire, not stand-ins.
     *
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext, 3: ForwardDraftFlowSpyAuditWriter}
     */
    private function bootWiredProviders(): array
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
        $auditWriter = new ForwardDraftFlowSpyAuditWriter();

        $kernelServices = new class ($dispatcher, $entityTypeManager, $configFactory, $accountContext, $auditWriter) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly ConfigFactoryInterface $configFactory,
                private readonly AccountContextInterface $accountContext,
                private readonly AuditWriterInterface $auditWriter,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    ConfigFactoryInterface::class => $this->configFactory,
                    AccountContextInterface::class => $this->accountContext,
                    AuditWriterInterface::class => $this->auditWriter,
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

        // Test-local workflow (WP-2 rework, #1920): the shipped `editorial`
        // workflow no longer ships a `revise` (published -> draft) edge, so
        // this spine persists its OWN workflow — the descoped shipped shape
        // plus a `revise` transition — and binds `node.article` to it above,
        // rather than to `editorial`. This keeps the engine coverage (the
        // forward-draft branch in TransitionService/WorkflowStateGuard is
        // dormant substrate, still reachable via a custom workflow) without
        // pretending the shipped workflow exposes the edge.
        $entityTypeManager->getRepository('workflow')->save($this->editorialForwardWorkflow());

        // Wires NodeRevisionDefaultListener (Task 2.3) onto PRE_SAVE.
        $nodeProvider->boot();
        // Wires WorkflowStateGuard + WorkflowPointerMoveGuard onto the same
        // dispatcher, and seeds the shipped `editorial` workflow (which no
        // longer carries a `revise` edge — see above).
        $workflowProvider->boot();

        // Rollback audit coverage (Task 2.5): wired directly with the real
        // listener class rather than via the full AuditServiceProvider
        // (which needs a real audit DB/schema this spine does not otherwise
        // exercise) — same production class, real dispatch, a spy writer
        // standing in for the DB-backed AuditEventWriter.
        $dispatcher->addSubscriber(new RollbackAuditListener($auditWriter, null, $accountContext));

        // A NodeType row for the bound bundle, matching realistic production
        // shape (Task 2.3's per-bundle new_revision knob; the entity-type
        // default already forces revisioning regardless of this row).
        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        return [$entityTypeManager, $workflowProvider, $accountContext, $auditWriter];
    }

    /**
     * Builds the test-local `editorial_forward` workflow: the exact
     * descoped `DefaultWorkflows::EDITORIAL` shape (states + the
     * submit_for_review/publish/reject/archive/restore/restore_to_published
     * transitions), plus a `revise` (published -> draft) transition the
     * shipped workflow deliberately no longer carries (WP-2 rework). Every
     * transition's permission is spelled out explicitly, re-derived for THIS
     * workflow's id — {@see Workflow::permissionFor()} only falls back to a
     * derived `use {workflow_id} transition {transition_id}` name when a
     * transition's own `permission` is empty, and the seed data (mirrored
     * here) always sets it explicitly.
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

final class ForwardDraftFlowSpyAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->recorded[] = $descriptor;
    }
}

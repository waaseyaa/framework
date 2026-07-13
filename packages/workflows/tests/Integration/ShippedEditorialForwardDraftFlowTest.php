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
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * CW-v1 option-1 (#1920 PR-6, design §7 "Restore `revise` to the shipped
 * editorial workflow"): {@see ForwardDraftFlowTest} already proves the full
 * default-revision-discipline engine spine end to end on a test-local
 * `editorial_forward` workflow (that file's own docblock explains the
 * deliberate choice — the shipped `editorial` workflow, as it stood before
 * this PR, did not carry a `revise` edge). This file does NOT duplicate that
 * spine. It is the smaller, honest addition design §7/§11 call for: proof
 * that the REAL {@see WorkflowServiceProvider::boot()}-SEEDED `editorial`
 * workflow entity — the one every production install actually gets, with no
 * test-local substitute — now carries a working `revise` edge and the
 * forward-draft mechanics compose correctly through it, including the
 * archived-recovery round trip.
 *
 * Byte-stability oracle: `find()` (the served base row), matching the
 * storage-level oracle {@see ForwardDraftFlowTest} and
 * `docs/specs/content-workflow.md` document as the sanctioned fallback — an
 * anonymous JSON:API GET oracle is explicitly out of scope for this
 * engine-focused package (design §11: "otherwise a storage-level find()
 * oracle suffices"; the HTTP-level oracle is PR-3's surface-pointer-
 * awareness territory). `find()` already IS what an anonymous, unauthenticated
 * reader is served — no `EntityAccessHandler`/`AccountInterface` gating
 * exists between it and the base row for a status-published node — so the
 * byte-stability assertions below stand in for that read directly.
 */
#[CoversNothing]
final class ShippedEditorialForwardDraftFlowTest extends TestCase
{
    #[Test]
    public function the_boot_seeded_editorial_workflow_carries_a_working_revise_edge_through_the_full_archived_recovery_round_trip(): void
    {
        [$entityTypeManager, $provider, $accountContext, $db] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        // Proves this test truly runs against the REAL boot-seeded shipped
        // workflow, not a stand-in: the persisted `editorial` entity (not an
        // in-memory `DefaultWorkflows::EDITORIAL` read) must carry `revise`.
        $shippedEditorial = $entityTypeManager->getRepository('workflow')->find('editorial');
        $this->assertInstanceOf(Workflow::class, $shippedEditorial);
        $this->assertTrue(
            $shippedEditorial->hasTransition('revise'),
            'The boot-seeded editorial workflow entity must carry the revise transition (CW-v1 option-1 PR-6).',
        );

        $editor = $this->account(21, [
            'use editorial transition publish',
            'use editorial transition revise',
            'use editorial transition submit_for_review',
            'use editorial transition archive',
            'use editorial transition restore',
            'use editorial transition restore_to_published',
        ]);
        $accountContext->set($editor);

        // --- 1. Create + publish: pointer established, base row = ---
        // published content.
        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $publishResult = $transitionService->transition($created, 'publish', $editor);
        $this->assertSame('published', $publishResult->toState);

        $firstPublished = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($firstPublished);
        $this->assertSame('Original title', $firstPublished->get('title'));
        $this->assertSame(1, (int) $nodeRepository->find($entityId)?->get('status'));

        // --- 2. `revise` (published -> draft) on the SHIPPED workflow: ---
        // the forward-draft entry edge this PR restores. `find()` — the
        // anonymous-visible read path — must stay byte-stable across every
        // column of the base row.
        $baseRowBeforeRevise = $this->rawBaseRow($db, $entityId);

        $tip = $nodeRepository->find($entityId);
        $this->assertNotNull($tip);
        \assert($tip instanceof Node);
        $tip->setTitle('Forward draft title');
        $reviseResult = $transitionService->transition($tip, 'revise', $editor);
        $this->assertSame('draft', $reviseResult->toState);

        $baseRowAfterRevise = $this->rawBaseRow($db, $entityId);
        $this->assertSame(
            $baseRowBeforeRevise,
            $baseRowAfterRevise,
            'revise on the shipped editorial workflow must leave the base row (the anonymous-visible content) '
            . 'BYTE-IDENTICAL — every column, not spot-checked.',
        );

        $servedDuringDraft = $nodeRepository->find($entityId);
        $this->assertNotNull($servedDuringDraft);
        $this->assertSame('Original title', $servedDuringDraft->get('title'), 'find() must keep serving the published title during the draft window.');
        $this->assertSame('published', $servedDuringDraft->get('workflow_state'));
        $this->assertSame(1, (int) $servedDuringDraft->get('status'));

        $workingCopy = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($workingCopy);
        $this->assertSame('Forward draft title', $workingCopy->get('title'), 'loadWorkingCopy() must serve the draft title.');
        $this->assertSame('draft', $workingCopy->get('workflow_state'));

        // --- 3. submit_for_review -> publish: promotion through the ---
        // shipped workflow's normal review path, base row byte-stable
        // until the promotion itself.
        $draftTip = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($draftTip);
        $submitResult = $transitionService->transition($draftTip, 'submit_for_review', $editor);
        $this->assertSame('review', $submitResult->toState);

        $baseRowAfterSubmit = $this->rawBaseRow($db, $entityId);
        $this->assertSame($baseRowBeforeRevise, $baseRowAfterSubmit, 'The base row must still be byte-identical after the submit_for_review draft save.');

        $reviewTip = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($reviewTip);
        $promoteResult = $transitionService->transition($reviewTip, 'publish', $editor);
        $this->assertSame('published', $promoteResult->toState);

        $promoted = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($promoted);
        $this->assertSame('Forward draft title', $promoted->get('title'));
        $baseRowAfterPromotion = $this->rawBaseRow($db, $entityId);
        $this->assertSame('Forward draft title', $baseRowAfterPromotion['title'], 'Promotion moves the draft content into the base row via setPublishedRevision().');
        $this->assertNotSame($baseRowBeforeRevise, $baseRowAfterPromotion, 'Promotion is the one moment the base row is allowed to change.');

        // --- 4. archive -> restore -> restore_to_published round trip: ---
        // 'archive' moves the pointer to the archived revision (status=0).
        // 'restore' (archived -> draft) is itself a forward-draft-style entry
        // FROM a default-revision state — a new draft tip is created while
        // the published pointer stays on the archived revision, base row
        // byte-stable. Publishing that draft tip fires the shipped 'publish'
        // edge (draft -> published), but the underlying POINTER move is a
        // different-state archived -> published move — the shipped workflow
        // only has an edge for that via 'restore_to_published', so
        // WorkflowPointerMoveGuard authorizes it against THAT transition's
        // permission (which $editor holds), closing the archived-recovery
        // loop back to a served, anonymous-visible published node. This is
        // the exact "archived → restore → publish round trip" the retired
        // deferral note in docs/specs/content-workflow.md names.
        $archiveSubject = $nodeRepository->find($entityId);
        $this->assertNotNull($archiveSubject);
        $archiveResult = $transitionService->transition($archiveSubject, 'archive', $editor);
        $this->assertSame('archived', $archiveResult->toState);

        $archived = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($archived);
        $this->assertSame('archived', $archived->get('workflow_state'));
        $this->assertSame(0, (int) $nodeRepository->find($entityId)?->get('status'));

        $baseRowBeforeRestore = $this->rawBaseRow($db, $entityId);

        $archivedTip = $nodeRepository->find($entityId);
        $this->assertNotNull($archivedTip);
        \assert($archivedTip instanceof Node);
        $restoreResult = $transitionService->transition($archivedTip, 'restore', $editor);
        $this->assertSame('draft', $restoreResult->toState);

        $baseRowAfterRestore = $this->rawBaseRow($db, $entityId);
        $this->assertSame(
            $baseRowBeforeRestore,
            $baseRowAfterRestore,
            'restore (archived -> draft) must leave the base row byte-identical — it is a forward-draft-style '
            . 'entry from a default-revision state, not a promotion.',
        );

        $servedDuringRestoreDraft = $nodeRepository->find($entityId);
        $this->assertNotNull($servedDuringRestoreDraft);
        $this->assertSame('archived', $servedDuringRestoreDraft->get('workflow_state'), 'find() still serves the archived pointer while the restore draft is pending.');

        $restoreDraftTip = $nodeRepository->loadWorkingCopy($entityId);
        $this->assertNotNull($restoreDraftTip);
        $roundTripResult = $transitionService->transition($restoreDraftTip, 'publish', $editor);
        $this->assertSame('published', $roundTripResult->toState);

        $roundTripped = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($roundTripped);
        $this->assertSame('published', $roundTripped->get('workflow_state'));
        $this->assertSame(1, (int) $nodeRepository->find($entityId)?->get('status'));
        $this->assertSame('Forward draft title', $nodeRepository->find($entityId)?->get('title'), 'The round trip republishes the same content the archived revision carried.');
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
     * Wires the REAL {@see NodeServiceProvider} and {@see WorkflowServiceProvider}
     * against a stub kernel-services bus, binds `node.article` to the shipped
     * `editorial` workflow id (NOT a test-local workflow), and boots — the
     * SAME real `WorkflowServiceProvider::boot()` call every production
     * install runs, which seeds `DefaultWorkflows::EDITORIAL` (now including
     * `revise`) as the persisted `editorial` config entity.
     *
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext, 3: DBALDatabase}
     */
    private function bootWiredProviders(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'editorial',
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

        // Wires NodeRevisionDefaultListener onto PRE_SAVE.
        $nodeProvider->boot();
        // Wires WorkflowStateGuard + WorkflowPointerMoveGuard +
        // WorkflowRepublishListener AND seeds the shipped `editorial`
        // workflow entity via the real seed/top-up path — no test-local
        // workflow is ever persisted in this file.
        $workflowProvider->boot();

        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        return [$entityTypeManager, $workflowProvider, $accountContext, $db];
    }
}

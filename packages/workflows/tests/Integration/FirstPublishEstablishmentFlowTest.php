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
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Relationship\RelationshipServiceProvider;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;
use Waaseyaa\Workflows\WorkflowTransition;

/**
 * CW-v1 option-1 PR-5 (design §6, #1920): the first-publish pointer-
 * ESTABLISHMENT integration spine — final-review finding #5. A review-
 * required workflow (`draft -> review` via `submit`, `review -> published`
 * via `approve`, deliberately NO `draft -> published` edge — the shape
 * CW-v1's department-routing use case is built for) bound to the REAL `Node`
 * entity type. Before the fix, `WorkflowPointerMoveGuard::currentlyEffectiveState()`
 * falls back to the workflow's `initial_state` ('draft') for a never-published
 * entity's `publish` pointer move, and the strict different-state rule then
 * demands a `draft -> published` edge that this workflow deliberately does
 * not have — every first publish permanently wedges with
 * `REASON_ILLEGAL_EDGE` AFTER the revision-creating save already committed
 * (`TransitionService::transition()`'s promote branch, `packages/workflows/src/Transition/TransitionService.php`).
 *
 * The fix (design §6): `operation === 'publish' && fromRevisionId === null`
 * is pointer ESTABLISHMENT, not a state crossing. It is governed by the
 * same-state branch's any-of rule against the TARGET revision's state
 * instead of a fabricated `initial_state -> target` edge check.
 */
#[CoversNothing]
final class FirstPublishEstablishmentFlowTest extends TestCase
{
    #[Test]
    public function first_publish_on_a_strict_review_required_workflow_succeeds_end_to_end(): void
    {
        [$entityTypeManager, $provider, $accountContext, $auditWriter] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        // 'approve' is the ONLY edge into 'published' in this workflow, and
        // it is reachable only from 'review' — there is no 'draft ->
        // published' edge for the pre-fix fallback to satisfy.
        $decisionmaker = $this->account(31, ['use review_required transition approve']);
        $author = $this->account(30, ['use review_required transition submit']);
        $accountContext->set($author);

        // --- 1. Create: guard forces draft/unpublished. ---
        $node = new Node(['title' => 'Quarterly report', 'type' => 'article', 'slug' => 'quarterly-report']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $this->assertSame('draft', $created->get('workflow_state'));
        $this->assertSame(0, (int) $created->get('status'));
        $this->assertNull($nodeRepository->loadPublishedRevision($entityId), 'No published pointer exists before the first publish.');

        // --- 2. Submit for review: draft -> review, a real edge. ---
        $submitResult = $transitionService->transition($created, 'submit', $author);
        $this->assertSame('review', $submitResult->toState);

        $inReview = $nodeRepository->find($entityId);
        $this->assertNotNull($inReview);
        $this->assertSame('review', $inReview->get('workflow_state'));

        // --- 3. Approve: review -> published. This is the FIRST publish ---
        // of a never-published entity — the pointer-move guard sees
        // `fromRevisionId === null` on the `setPublishedRevision()` call
        // TransitionService's promote branch fires. Before the fix, this
        // throws TransitionDeniedException(REASON_ILLEGAL_EDGE) because the
        // guard fabricates a `draft -> published` edge check that does not
        // exist in this workflow.
        $accountContext->set($decisionmaker);
        $approveResult = $transitionService->transition($nodeRepository->find($entityId), 'approve', $decisionmaker);
        $this->assertSame('published', $approveResult->toState);

        // --- 4. Pointer established: status=1, find() serves it. ---
        $published = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($published, 'The published pointer must be established.');
        $this->assertSame('Quarterly report', $published->get('title'));
        $this->assertSame(1, (int) $published->get('status'));

        $served = $nodeRepository->find($entityId);
        $this->assertNotNull($served);
        $this->assertSame('published', $served->get('workflow_state'));
        $this->assertSame(1, (int) $served->get('status'));
        $this->assertSame((string) $published->get('revision_id'), (string) $served->get('revision_id'));

        // --- 5. Audit trail complete: both TransitionService calls allowed. ---
        $transitionRecords = \array_values(\array_filter(
            $auditWriter->recorded,
            static fn(AuditEventDescriptor $d): bool => $d->kind === AuditEventKind::WorkflowTransition,
        ));
        $this->assertCount(2, $transitionRecords);
        foreach ($transitionRecords as $record) {
            $this->assertSame('allowed', $record->outcome);
        }
        $this->assertSame('submit', $transitionRecords[0]->attributes['transition']);
        $this->assertSame('approve', $transitionRecords[1]->attributes['transition']);
    }

    #[Test]
    public function group_constrained_first_publish_succeeds_for_a_member_and_is_denied_for_a_non_member(): void
    {
        [$entityTypeManager, $provider, $accountContext, $auditWriter] = $this->bootWiredGroupProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        $author = $this->account(40, ['use review_required_dept transition submit']);
        $memberDecisionmaker = $this->account(41, ['use review_required_dept transition approve']);
        $nonMemberDecisionmaker = $this->account(42, ['use review_required_dept transition approve']);

        $this->addUserToGroup($entityTypeManager, '41', 'dept_a');
        $this->addUserToGroup($entityTypeManager, '42', 'dept_b');

        $accountContext->set($author);
        $node = new Node(['title' => 'Dept memo', 'type' => 'article', 'slug' => 'dept-memo']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $this->addContentToGroup($entityTypeManager, $entityId, 'dept_a');

        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $transitionService->transition($created, 'submit', $author);

        // --- Non-member (holds the permission, NOT a dept_a member): the ---
        // establishment any-of rule must not weaken WP-3 — the group-
        // constraint gate on the fired 'approve' TRANSITION itself denies
        // this before any save happens, so the pointer-move guard's
        // establishment branch is never even reached.
        $accountContext->set($nonMemberDecisionmaker);
        $denied = null;
        try {
            $transitionService->transition($nodeRepository->find($entityId), 'approve', $nonMemberDecisionmaker);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $denied->reason);

        $afterDenial = $nodeRepository->find($entityId);
        $this->assertNotNull($afterDenial);
        $this->assertSame('review', $afterDenial->get('workflow_state'), 'A denied attempt must not move the state.');
        $this->assertNull($nodeRepository->loadPublishedRevision($entityId), 'A denied attempt must not establish a pointer.');

        // --- Member (holds the permission AND dept_a membership): the ---
        // establishment any-of rule composes with the WP-3 group check —
        // first publish succeeds end-to-end.
        $accountContext->set($memberDecisionmaker);
        $approveResult = $transitionService->transition($nodeRepository->find($entityId), 'approve', $memberDecisionmaker);
        $this->assertSame('published', $approveResult->toState);

        $published = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($published);
        $this->assertSame(1, (int) $published->get('status'));
    }

    #[Test]
    public function direct_establishment_is_denied_without_a_transition_into_the_target_state_permission(): void
    {
        // Deliberately targets 'published' (not 'draft'): 'draft' equals
        // this workflow's initial_state, so the PRE-FIX fallback and the
        // POST-FIX establishment rule coincidentally agree there — no red
        // proof. 'published' is only reachable from 'draft' via an
        // INTERMEDIATE state in this workflow (no draft->published edge),
        // so the two rules diverge in their DENIAL REASON: pre-fix, the
        // guard fabricates a 'draft -> published' edge check and denies
        // REASON_ILLEGAL_EDGE; post-fix, the any-of establishment rule
        // correctly denies REASON_PERMISSION.
        [$entityTypeManager, $provider, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $provider->resolve(TransitionService::class);

        $author = $this->account(50, ['use review_required transition submit']);
        $approver = $this->account(54, ['use review_required transition approve']);
        $attacker = $this->account(55, ['use review_required transition submit']);

        $accountContext->set($author);
        $node = new Node(['title' => 'Never published', 'type' => 'article', 'slug' => 'never-published']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $transitionService->transition($nodeRepository->find($entityId), 'submit', $author);

        // Build an orphan 'published'-stamped tip WITHOUT ever moving the
        // pointer: a raw content save (not through TransitionService) by an
        // account holding the real review->published edge's own permission
        // ('approve'). This is exactly the WP-2 "denied-promotion residue"
        // shape — a tip stamped 'published' with no published pointer.
        $accountContext->set($approver);
        $reviewTip = $nodeRepository->find($entityId);
        $this->assertNotNull($reviewTip);
        \assert($reviewTip instanceof Node);
        $reviewTip->set('workflow_state', 'published');
        $nodeRepository->save($reviewTip);

        $orphanTip = $nodeRepository->find($entityId);
        $this->assertNotNull($orphanTip);
        $this->assertSame('published', $orphanTip->get('workflow_state'));
        $orphanRevisionId = (int) $orphanTip->get('revision_id');
        $this->assertNull($nodeRepository->loadPublishedRevision($entityId), 'Still never published: the pointer has not moved.');

        // The attacker holds a transition permission ('submit'), but not
        // one that targets 'published' ('approve').
        $accountContext->set($attacker);
        $denied = null;
        try {
            $nodeRepository->setPublishedRevision($entityId, $orphanRevisionId);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        $this->assertNull($nodeRepository->loadPublishedRevision($entityId), 'A denied direct establishment must write nothing.');
        $afterDenial = $nodeRepository->find($entityId);
        $this->assertNotNull($afterDenial);
        $this->assertSame($orphanRevisionId, (int) $afterDenial->get('revision_id'), 'The base row must be unchanged by a denied establishment.');
    }

    #[Test]
    public function null_account_context_establishment_is_allowed(): void
    {
        [$entityTypeManager, , $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $accountContext->set($this->account(51, ['use review_required transition submit']));
        $node = new Node(['title' => 'Bootstrap import', 'type' => 'article', 'slug' => 'bootstrap-import']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $revisionId = (int) $nodeRepository->find($entityId)?->get('revision_id');

        // Null ambient context (CLI/queue/bootstrap): edge-legality only,
        // and establishment has no edge to check.
        $accountContext->set(null);
        $entity = $nodeRepository->setPublishedRevision($entityId, $revisionId);

        $this->assertSame((string) $revisionId, (string) $entity->get('revision_id'));
        $published = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($published);
        $this->assertSame((string) $revisionId, (string) $published->get('revision_id'));
    }

    #[Test]
    public function an_account_holding_only_a_transition_into_draft_may_establish_a_benign_draft_stamped_pointer(): void
    {
        // Pinned known-benign quirk (design §6, verifier finding 5): an
        // account holding only 'reject' (a transition INTO 'draft')
        // satisfies the establishment any-of rule for a DRAFT-stamped
        // revision. The outcome is benign: the pointer now points at an
        // unpublished-shaped revision, so derived `status` stays 0 and
        // nothing is publicly served — reaching a VISIBLE (published: true)
        // pointer still requires a publish-class permission.
        [$entityTypeManager, , $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $accountContext->set($this->account(52, ['use review_required transition submit']));
        $node = new Node(['title' => 'Quirk case', 'type' => 'article', 'slug' => 'quirk-case']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $draftRevision = $nodeRepository->find($entityId);
        $this->assertNotNull($draftRevision);
        $this->assertSame('draft', $draftRevision->get('workflow_state'));
        $this->assertSame(0, (int) $draftRevision->get('status'));
        $draftRevisionId = (int) $draftRevision->get('revision_id');

        $accountContext->set($this->account(53, ['use review_required transition reject']));
        $established = $nodeRepository->setPublishedRevision($entityId, $draftRevisionId);

        $this->assertSame('draft', $established->get('workflow_state'));
        $this->assertSame(0, (int) $established->get('status'), 'A draft-stamped pointer must derive status=0 — nothing is publicly served.');

        $served = $nodeRepository->find($entityId);
        $this->assertNotNull($served);
        $this->assertSame(0, (int) $served->get('status'));
        $this->assertSame('draft', $served->get('workflow_state'));
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

    private function addContentToGroup(EntityTypeManager $manager, string $entityId, string $groupId): void
    {
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => $entityId,
            'to_entity_type' => 'group',
            'to_entity_id' => $groupId,
        ]);
    }

    private function addUserToGroup(EntityTypeManager $manager, string $uid, string $groupId): void
    {
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => $uid,
            'to_entity_type' => 'group',
            'to_entity_id' => $groupId,
        ]);
    }

    /**
     * @param array{relationship_type: string, from_entity_type: string, from_entity_id: string, to_entity_type: string, to_entity_id: string} $values
     */
    private function createRelationship(EntityTypeManager $manager, array $values): void
    {
        $repository = $manager->getRepository('relationship');
        $entity = $repository->create($values + ['directionality' => 'directed', 'status' => 1]);
        $repository->save($entity, validate: false);
    }

    /**
     * The strict review-required shape this spine exists to unwedge:
     * `draft -> review` ('submit'), `review -> published` ('approve'),
     * `review -> draft` ('reject') — deliberately NO `draft -> published`
     * edge, so the pre-fix guard's `initial_state` fallback + strict
     * different-state rule always wedges the first publish.
     */
    private function reviewRequiredWorkflow(): Workflow
    {
        $transitions = [
            'submit' => ['label' => 'Submit for review', 'from' => ['draft'], 'to' => 'review'],
            'approve' => ['label' => 'Approve', 'from' => ['review'], 'to' => 'published'],
            'reject' => ['label' => 'Send back', 'from' => ['review'], 'to' => 'draft'],
        ];
        foreach ($transitions as $id => $transition) {
            $transition['permission'] = \sprintf('use review_required transition %s', $id);
            $transitions[$id] = $transition;
        }

        $workflow = new Workflow([
            'id' => 'review_required',
            'label' => 'Review required (test-local, no draft->published edge)',
            'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft', 'published' => false, 'default_revision' => false],
                'review' => ['label' => 'In review', 'published' => false, 'default_revision' => false],
                'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
            ],
            'transitions' => $transitions,
        ]);
        $workflow->enforceIsNew();

        return $workflow;
    }

    private function reviewRequiredGroupWorkflow(): Workflow
    {
        $transitions = [
            'submit' => ['label' => 'Submit for review', 'from' => ['draft'], 'to' => 'review'],
            'approve' => ['label' => 'Approve', 'from' => ['review'], 'to' => 'published', 'group_constraint' => WorkflowTransition::GROUP_CONSTRAINT_CONTENT_GROUPS],
            'reject' => ['label' => 'Send back', 'from' => ['review'], 'to' => 'draft'],
        ];
        foreach ($transitions as $id => $transition) {
            $transition['permission'] = \sprintf('use review_required_dept transition %s', $id);
            $transitions[$id] = $transition;
        }

        $workflow = new Workflow([
            'id' => 'review_required_dept',
            'label' => 'Review required (test-local, department routing)',
            'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft', 'published' => false, 'default_revision' => false],
                'review' => ['label' => 'In review', 'published' => false, 'default_revision' => false],
                'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
            ],
            'transitions' => $transitions,
        ]);
        $workflow->enforceIsNew();

        return $workflow;
    }

    /**
     * Wires real dispatcher + real SQLite-backed EntityTypeManager (node,
     * node_type, workflow), boots the REAL {@see NodeServiceProvider} and
     * {@see WorkflowServiceProvider} — same shape as {@see ForwardDraftFlowTest}.
     *
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext, 3: FirstPublishEstablishmentFlowSpyAuditWriter}
     */
    private function bootWiredProviders(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'review_required',
        ]);
        $configFactory = new ConfigFactory($configStorage, $dispatcher);

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $this->repositoryFactory($dispatcher, $db));
        $accountContext = new RequestAccountContext();
        $auditWriter = new FirstPublishEstablishmentFlowSpyAuditWriter();

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

        $entityTypeManager->getRepository('workflow')->save($this->reviewRequiredWorkflow());

        $nodeProvider->boot();
        $workflowProvider->boot();

        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        return [$entityTypeManager, $workflowProvider, $accountContext, $auditWriter];
    }

    /**
     * As {@see bootWiredProviders()}, plus {@see RelationshipServiceProvider}
     * and {@see GroupsServiceProvider} for the group-constrained scenario —
     * same shape as {@see DepartmentRoutingFlowTest::bootWiredProviders()}.
     *
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext, 3: FirstPublishEstablishmentFlowSpyAuditWriter}
     */
    private function bootWiredGroupProviders(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'review_required_dept',
        ]);
        $configFactory = new ConfigFactory($configStorage, $dispatcher);

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $this->repositoryFactory($dispatcher, $db));
        $accountContext = new RequestAccountContext();
        $auditWriter = new FirstPublishEstablishmentFlowSpyAuditWriter();

        $nodeProvider = new NodeServiceProvider();
        $relationshipProvider = new RelationshipServiceProvider();
        $groupsProvider = new GroupsServiceProvider();
        $workflowProvider = new WorkflowServiceProvider();
        /** @var list<ServiceProvider> $providers */
        $providers = [$nodeProvider, $relationshipProvider, $groupsProvider, $workflowProvider];

        $kernelServices = new class ($dispatcher, $entityTypeManager, $configFactory, $accountContext, $auditWriter, static fn(): array => $providers) implements KernelServicesInterface {
            /**
             * @param \Closure(): list<ServiceProvider> $providersAccessor
             */
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly ConfigFactoryInterface $configFactory,
                private readonly AccountContextInterface $accountContext,
                private readonly AuditWriterInterface $auditWriter,
                private readonly \Closure $providersAccessor,
            ) {}

            public function get(string $abstract): ?object
            {
                $direct = match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    ConfigFactoryInterface::class => $this->configFactory,
                    AccountContextInterface::class => $this->accountContext,
                    AuditWriterInterface::class => $this->auditWriter,
                    default => null,
                };
                if ($direct !== null) {
                    return $direct;
                }

                foreach (($this->providersAccessor)() as $other) {
                    if (isset($other->getBindings()[$abstract])) {
                        return $other->resolve($abstract);
                    }
                }

                return null;
            }
        };

        foreach ($providers as $provider) {
            $provider->setKernelServices($kernelServices);
        }
        foreach ($providers as $provider) {
            $provider->register();
        }
        foreach ($providers as $provider) {
            foreach ($provider->getEntityTypes() as $entityType) {
                $entityTypeManager->registerEntityType($entityType);
            }
        }

        $entityTypeManager->getRepository('workflow')->save($this->reviewRequiredGroupWorkflow());

        $nodeProvider->boot();
        $relationshipProvider->boot();
        $groupsProvider->boot();
        $workflowProvider->boot();

        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        return [$entityTypeManager, $workflowProvider, $accountContext, $auditWriter];
    }

    /**
     * @return \Closure(string, EntityTypeInterface): EntityRepositoryInterface
     */
    private function repositoryFactory(SymfonyEventDispatcherAdapter $dispatcher, DBALDatabase $db): \Closure
    {
        return static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
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
    }
}

final class FirstPublishEstablishmentFlowSpyAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->recorded[] = $descriptor;
    }
}

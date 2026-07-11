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
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;
use Waaseyaa\Workflows\WorkflowTransition;

/**
 * The WP-3 integration spine (CW-v1, docs/specs/content-workflow.md, "Testing
 * requirements"): the client-driving story — department (group) routing — on
 * the REAL `Node` entity type, bound to a test-local `editorial_dept`
 * workflow persisted directly by this test. `editorial_dept` is the
 * descoped shipped `EDITORIAL` shape verbatim (KEEP `draft` in `publish`'s
 * `from[]` — the "first-publish shape" constraint documented in the spec's
 * "Group constraints (WP-3)" section: a group-routed workflow must keep an
 * initial-state -> published edge until the option-1 forward-draft follow-up
 * lands, or first publish permanently wedges the pointer guard) plus
 * `group_constraint: content_groups` on `submit_for_review`, `publish`, and
 * `reject`.
 *
 * Wired through the REAL {@see NodeServiceProvider},
 * {@see RelationshipServiceProvider}, {@see GroupsServiceProvider}, and
 * {@see WorkflowServiceProvider} — all four sharing one dispatcher and one
 * `EntityTypeManager`, exactly like {@see ForwardDraftFlowTest}'s bootstrap.
 * `WorkflowServiceProvider` resolves `GroupMembershipService` (bound by
 * `GroupsServiceProvider`) through the kernel-services bus's sibling-binding
 * fallback, mirroring the production
 * {@see \Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices}
 * behavior rather than hand-wiring the collaborator directly.
 *
 * Story: author creates a node in dept_a's department (guard forces
 * draft/unpublished) -> author submits for review (member + permission) ->
 * decisionmaker_b (dept_b only) attempts publish, DENIED
 * `group_constraint`, persisted state unchanged -> `getAvailableTransitions`
 * excludes `publish` for decisionmaker_b, includes it for decisionmaker_a ->
 * author (dept_a member, but lacking the `publish` permission) attempts
 * publish, DENIED `permission` (precedence pinned: the permission gate fires
 * before the group gate is even evaluated, even though author's own
 * membership would otherwise satisfy it) -> decisionmaker_a (dept_a member,
 * holds `publish`) publishes: pointer establishes, status flips -> full
 * audit trail assertion across all four `TransitionService` calls.
 */
#[CoversNothing]
final class DepartmentRoutingFlowTest extends TestCase
{
    #[Test]
    public function department_routing_end_to_end_on_a_real_node_through_a_test_local_group_constrained_workflow(): void
    {
        [$entityTypeManager, $workflowProvider, $accountContext, $auditWriter] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');
        $transitionService = $workflowProvider->resolve(TransitionService::class);

        $author = $this->account(21, ['use editorial_dept transition submit_for_review']);
        $decisionmakerA = $this->account(22, [
            'use editorial_dept transition publish',
            'use editorial_dept transition archive',
        ]);
        $decisionmakerB = $this->account(23, ['use editorial_dept transition publish']);

        // --- Fixtures: two departments, content in dept_a, author + ---
        // decisionmaker_a members of dept_a, decisionmaker_b member of
        // dept_b ONLY.
        $this->addUserToGroup($entityTypeManager, '21', 'dept_a');
        $this->addUserToGroup($entityTypeManager, '22', 'dept_a');
        $this->addUserToGroup($entityTypeManager, '23', 'dept_b');

        // --- 1. Create: the save-path guard forces initial_state + ---
        // unpublished, regardless of Node's own constructor default
        // (status defaults to 1 when not explicitly given).
        $accountContext->set($author);
        $node = new Node(['title' => 'Q3 department memo', 'type' => 'article', 'slug' => 'q3-department-memo']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();

        $this->addContentToGroup($entityTypeManager, $entityId, 'dept_a');

        $created = $nodeRepository->find($entityId);
        $this->assertNotNull($created);
        $this->assertSame('draft', $created->get('workflow_state'));
        $this->assertSame(0, (int) $created->get('status'));

        // --- 2. Author submits for review: member of the content's own ---
        // group AND holds the transition's permission -> allowed. Proves
        // the group-constrained path is not just a denial mechanism.
        // Every call below also updates $accountContext to the acting
        // account: TransitionService's own gates read the AccountInterface
        // argument, but WorkflowStateGuard (the PRE_SAVE save-path guard
        // TransitionService's persistence step runs through) reads the
        // AMBIENT AccountContextInterface instead — the two must be kept in
        // lockstep for every actor change, exactly like ForwardDraftFlowTest.
        $submitResult = $transitionService->transition($created, 'submit_for_review', $author);
        $this->assertSame('review', $submitResult->toState);

        $inReview = $nodeRepository->find($entityId);
        $this->assertNotNull($inReview);
        $this->assertSame('review', $inReview->get('workflow_state'));

        // --- 3. decisionmaker_b (dept_b member, holds the 'publish' ---
        // permission, but NOT a member of dept_a, the content's own group)
        // attempts publish: denied at the group-constraint gate, not the
        // permission gate.
        $accountContext->set($decisionmakerB);
        $deniedByGroup = null;
        try {
            $transitionService->transition($nodeRepository->find($entityId), 'publish', $decisionmakerB);
        } catch (TransitionDeniedException $e) {
            $deniedByGroup = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $deniedByGroup);
        $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $deniedByGroup->reason);

        // Persisted state must be completely unchanged by the denied
        // attempt (the gate fires before any repository write).
        $afterGroupDenial = $nodeRepository->find($entityId);
        $this->assertNotNull($afterGroupDenial);
        $this->assertSame('review', $afterGroupDenial->get('workflow_state'));
        $this->assertSame(0, (int) $afterGroupDenial->get('status'));

        // --- 4. getAvailableTransitions is the sanctioned read side for ---
        // UIs: it must not offer decisionmaker_b a transition they would be
        // denied, but must offer it to decisionmaker_a, who holds the same
        // permission AND the right department membership.
        $currentTip = $nodeRepository->find($entityId);
        $this->assertNotNull($currentTip);

        $availableForB = \array_map(
            static fn(WorkflowTransition $t): string => $t->id,
            $transitionService->getAvailableTransitions($currentTip, $decisionmakerB),
        );
        $availableForA = \array_map(
            static fn(WorkflowTransition $t): string => $t->id,
            $transitionService->getAvailableTransitions($currentTip, $decisionmakerA),
        );
        $this->assertNotContains('publish', $availableForB, 'decisionmaker_b must not be offered a group-denied transition.');
        $this->assertContains('publish', $availableForA, 'decisionmaker_a holds both the permission and the department membership.');

        // --- 5. Permission-precedence, pinned end-to-end: author is a ---
        // dept_a member (would satisfy the group constraint) but was never
        // granted the 'publish' permission. This must run while the node is
        // still in 'review' (publish is still a legal edge from this state)
        // so the denial actually exercises the permission gate rather than
        // the illegal-edge gate. The permission check runs BEFORE the group
        // check in TransitionService::transition() (see
        // docs/specs/content-workflow.md "Concepts and config schema"), so
        // the reason must be 'permission', not 'group_constraint', even
        // though membership alone would have passed.
        $accountContext->set($author);
        $deniedByPermission = null;
        try {
            $transitionService->transition($nodeRepository->find($entityId), 'publish', $author);
        } catch (TransitionDeniedException $e) {
            $deniedByPermission = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $deniedByPermission);
        $this->assertSame(
            TransitionDeniedException::REASON_PERMISSION,
            $deniedByPermission->reason,
            'Permission denial must win over group-constraint denial even for a member of the right department.',
        );

        $stillInReview = $nodeRepository->find($entityId);
        $this->assertNotNull($stillInReview);
        $this->assertSame('review', $stillInReview->get('workflow_state'));

        // --- 6. decisionmaker_a publishes: pointer establishes, status ---
        // flips (mirrors ForwardDraftFlowTest's first-publish assertions).
        $accountContext->set($decisionmakerA);
        $publishResult = $transitionService->transition($nodeRepository->find($entityId), 'publish', $decisionmakerA);
        $this->assertSame('published', $publishResult->toState);

        $published = $nodeRepository->loadPublishedRevision($entityId);
        $this->assertNotNull($published);
        $this->assertSame('Q3 department memo', $published->get('title'));
        $this->assertSame(1, (int) $published->get('status'));
        $this->assertSame(1, (int) $nodeRepository->find($entityId)?->get('status'));
        $this->assertSame('published', $nodeRepository->find($entityId)?->get('workflow_state'));

        // --- 7. Audit trail: one entry per TransitionService call, in ---
        // order — submit_for_review (allowed), decisionmaker_b's publish
        // attempt (denied), author's publish attempt (denied),
        // decisionmaker_a's publish (allowed). The audit descriptor itself
        // does not persist the typed denial `reason` (only `outcome`) —
        // {@see TransitionDeniedException::$reason} above is the
        // authoritative source for that, exactly as in EditorialFlowTest
        // and ForwardDraftFlowTest; here the two denial records are told
        // apart by `accountUid`, which the audit writer DOES persist.
        $this->assertCount(4, $auditWriter->recorded, 'Expected one audit entry per TransitionService call (2 allowed + 2 denied).');
        $outcomes = \array_map(static fn(AuditEventDescriptor $d): string => $d->outcome, $auditWriter->recorded);
        $this->assertSame(['allowed', 'denied', 'denied', 'allowed'], $outcomes);

        [$submitEntry, $groupDenialEntry, $permissionDenialEntry, $publishEntry] = $auditWriter->recorded;

        $this->assertSame('allowed', $submitEntry->outcome);
        $this->assertSame(21, $submitEntry->accountUid);
        $this->assertSame('submit_for_review', $submitEntry->attributes['transition']);
        $this->assertSame('draft', $submitEntry->attributes['from']);
        $this->assertSame('review', $submitEntry->attributes['to']);

        $this->assertSame('denied', $groupDenialEntry->outcome);
        $this->assertSame(23, $groupDenialEntry->accountUid, 'The group-constraint denial belongs to decisionmaker_b.');
        $this->assertSame('publish', $groupDenialEntry->attributes['transition']);
        $this->assertSame('review', $groupDenialEntry->attributes['from']);

        $this->assertSame('denied', $permissionDenialEntry->outcome);
        $this->assertSame(21, $permissionDenialEntry->accountUid, 'The permission denial belongs to author.');
        $this->assertSame('publish', $permissionDenialEntry->attributes['transition']);

        $this->assertSame('allowed', $publishEntry->outcome);
        $this->assertSame(22, $publishEntry->accountUid, 'The successful publish belongs to decisionmaker_a.');
        $this->assertSame('publish', $publishEntry->attributes['transition']);
        $this->assertSame('published', $publishEntry->attributes['to']);
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
     * Wires real dispatcher + real SQLite-backed EntityTypeManager (node,
     * node_type, relationship, group, group_type, workflow), then boots the
     * REAL {@see NodeServiceProvider}, {@see RelationshipServiceProvider},
     * {@see GroupsServiceProvider}, and {@see WorkflowServiceProvider}
     * against a stub kernel-services bus. The bus serves the fixed set of
     * collaborators (dispatcher, entity-type manager, config factory,
     * account context, audit writer) directly, and falls back to scanning
     * sibling providers' own bindings for everything else — the same
     * fallback shape as production's
     * {@see \Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices},
     * which is how `WorkflowServiceProvider` reaches `GroupsServiceProvider`'s
     * `GroupMembershipService` binding without this test hand-wiring it.
     *
     * @return array{0: EntityTypeManager, 1: WorkflowServiceProvider, 2: RequestAccountContext, 3: DepartmentRoutingFlowSpyAuditWriter}
     */
    private function bootWiredProviders(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'editorial_dept',
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
        $auditWriter = new DepartmentRoutingFlowSpyAuditWriter();

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

                // Sibling-binding fallback (mirrors
                // ProviderRegistryKernelServices::get()): lets
                // WorkflowServiceProvider resolve GroupMembershipService,
                // which GroupsServiceProvider binds, through this same bus.
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

        // Test-local workflow (WP-3, #1920): the shipped `editorial`
        // workflow carries no group_constraint (unconstrained by design —
        // "A workflow with no group constraints behaves exactly like Drupal
        // core"), so this spine persists its OWN workflow — the descoped
        // shipped shape plus `group_constraint: content_groups` on
        // `submit_for_review`/`publish`/`reject` — and binds `node.article`
        // to it above, rather than to `editorial`.
        $entityTypeManager->getRepository('workflow')->save($this->editorialDeptWorkflow());

        // Wires NodeRevisionDefaultListener (Task 2.3) onto PRE_SAVE.
        $nodeProvider->boot();
        // Wires the referential-integrity delete guard (unused by this
        // story, but real production wiring).
        $relationshipProvider->boot();
        // No-op boot() (GroupsServiceProvider has none to override).
        $groupsProvider->boot();
        // Wires WorkflowStateGuard + WorkflowPointerMoveGuard onto the same
        // dispatcher, and seeds the shipped `editorial` workflow (a
        // different id from `editorial_dept`, so no collision).
        $workflowProvider->boot();

        // A NodeType row for the bound bundle, matching realistic
        // production shape.
        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        return [$entityTypeManager, $workflowProvider, $accountContext, $auditWriter];
    }

    /**
     * Builds the test-local `editorial_dept` workflow: the exact descoped
     * `DefaultWorkflows::EDITORIAL` shape — states + the
     * submit_for_review/publish/reject/archive/restore/restore_to_published
     * transitions, `draft` KEPT in `publish`'s `from[]` (the spec's
     * "first-publish shape" note: a group-routed workflow must keep an
     * initial-state -> published edge until the option-1 forward-draft
     * follow-up lands) — plus `group_constraint: content_groups` on
     * `submit_for_review`, `publish`, and `reject` (the three transitions
     * this spine exercises; `archive`/`restore`/`restore_to_published` stay
     * unconstrained, matching the plan's minimal client-story scope).
     */
    private function editorialDeptWorkflow(): Workflow
    {
        $transitions = DefaultWorkflows::EDITORIAL['transitions'];
        foreach (['submit_for_review', 'publish', 'reject'] as $constrainedId) {
            $transitions[$constrainedId]['group_constraint'] = WorkflowTransition::GROUP_CONSTRAINT_CONTENT_GROUPS;
        }

        foreach ($transitions as $id => $transition) {
            $transition['permission'] = \sprintf('use editorial_dept transition %s', $id);
            $transitions[$id] = $transition;
        }

        $workflow = new Workflow([
            'id' => 'editorial_dept',
            'label' => 'Editorial (test-local, department routing)',
            'initial_state' => DefaultWorkflows::EDITORIAL['initial_state'],
            'states' => DefaultWorkflows::EDITORIAL['states'],
            'transitions' => $transitions,
        ]);
        $workflow->enforceIsNew();

        return $workflow;
    }
}

final class DepartmentRoutingFlowSpyAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->recorded[] = $descriptor;
    }
}

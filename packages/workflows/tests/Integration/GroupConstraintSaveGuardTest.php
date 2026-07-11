<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Group\GroupConstraintChecker;
use Waaseyaa\Workflows\Listener\WorkflowStateGuard;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;

/**
 * CW-v1 WP-3 T4 (#1920, docs/specs/content-workflow.md "Save-path guard"):
 * proves the raw-save bypass a group constraint would otherwise leave open
 * is closed. {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard} is wired
 * onto the REAL {@see EntityEvents::PRE_SAVE} dispatch (mirrors
 * {@see ImportSaveContextGuardTest}'s `bootWiredGuard()` shape, extended
 * with the group-constraint checker, real {@see GroupMembershipService}, and
 * real SQLite relationship storage — the same fixture shape T3's
 * `GroupConstraintTransitionTest` uses for `TransitionService`), so a raw
 * `EntityRepository::save()` that mutates `workflow_state` across a
 * group-constrained edge is validated exactly like
 * {@see \Waaseyaa\Workflows\Transition\TransitionService::transition()}
 * would validate it.
 */
#[CoversNothing]
final class GroupConstraintSaveGuardTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'dept_guard_subject';
    private const string WORKFLOW_ID = 'dept_guard';
    private const string CONTENT_GROUP_ID = '10';
    private const string OTHER_GROUP_ID = '99';

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    #[Test]
    public function a_raw_save_across_a_group_constrained_edge_is_denied_for_a_non_member_and_state_stays_unchanged(): void
    {
        [$manager, $accountContext] = $this->wireEngine();
        $repository = $manager->getRepository(self::ENTITY_TYPE_ID);

        $entity = $this->createEntityInReview($manager, $accountContext);
        $this->addContentToGroup($manager, (string) $entity->id(), self::CONTENT_GROUP_ID);
        // Account 8 belongs to a DIFFERENT group, not the content's group.
        $this->addUserToGroup($manager, '8', self::OTHER_GROUP_ID);

        $accountContext->set($this->account(8, ['use dept_guard transition publish']));
        $loaded = $repository->find((string) $entity->id());
        $loaded?->set('workflow_state', 'published');

        $denied = null;
        try {
            $repository->save($loaded);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }

        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $denied->reason);

        $stored = $repository->find((string) $entity->id());
        $this->assertSame('review', $stored?->get('workflow_state'), 'A denied raw save must not mutate persisted state.');
    }

    #[Test]
    public function a_raw_save_across_a_group_constrained_edge_is_allowed_for_a_member(): void
    {
        [$manager, $accountContext] = $this->wireEngine();
        $repository = $manager->getRepository(self::ENTITY_TYPE_ID);

        $entity = $this->createEntityInReview($manager, $accountContext);
        $this->addContentToGroup($manager, (string) $entity->id(), self::CONTENT_GROUP_ID);
        $this->addUserToGroup($manager, '7', self::CONTENT_GROUP_ID);

        $accountContext->set($this->account(7, ['use dept_guard transition publish']));
        $loaded = $repository->find((string) $entity->id());
        $loaded?->set('workflow_state', 'published');
        $repository->save($loaded);

        $stored = $repository->find((string) $entity->id());
        $this->assertSame('published', $stored?->get('workflow_state'));
    }

    #[Test]
    public function a_raw_save_with_a_null_account_context_is_unaffected_by_the_group_constraint(): void
    {
        // Pin: null context stays edge-legality-only, unchanged by WP-3 —
        // the group-constraint gate is skipped entirely (mirrors
        // WorkflowStateGuard::guardUpdate()'s existing null-context
        // permission pass-through), even with NO group_content /
        // group_membership rows recorded anywhere.
        [$manager, $accountContext] = $this->wireEngine();
        $repository = $manager->getRepository(self::ENTITY_TYPE_ID);

        $entity = $this->createEntityInReview($manager, $accountContext);
        $this->addContentToGroup($manager, (string) $entity->id(), self::CONTENT_GROUP_ID);

        $accountContext->set(null);
        $loaded = $repository->find((string) $entity->id());
        $loaded?->set('workflow_state', 'published');
        $repository->save($loaded);

        $stored = $repository->find((string) $entity->id());
        $this->assertSame('published', $stored?->get('workflow_state'));
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
     * Progresses a fresh entity from `draft` (the forced initial state on
     * create — no permission required) to `review` via a raw save across the
     * UNCONSTRAINED `submit_for_review` edge, using an admin account with
     * every `dept_guard` permission. Restores `$accountContext` to null
     * afterward so callers start each scenario from a clean ambient state.
     */
    private function createEntityInReview(EntityTypeManager $manager, RequestAccountContext $accountContext): GroupConstraintGuardSubject
    {
        $admin = $this->account(1, [
            'use dept_guard transition submit_for_review',
            'use dept_guard transition publish',
        ]);
        $accountContext->set($admin);

        $repository = $manager->getRepository(self::ENTITY_TYPE_ID);
        $entity = new GroupConstraintGuardSubject(['bundle' => self::ENTITY_TYPE_ID], self::ENTITY_TYPE_ID, $this->entityKeys());
        $repository->save($entity);

        $loaded = $repository->find((string) $entity->id());
        $loaded?->set('workflow_state', 'review');
        $repository->save($loaded);

        $accountContext->set(null);

        return $repository->find((string) $entity->id());
    }

    /**
     * @return array{0: EntityTypeManager, 1: RequestAccountContext}
     */
    private function wireEngine(): array
    {
        EntityType::clearFromClassCache();
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();
        $registry = new FieldDefinitionRegistry();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => self::WORKFLOW_ID,
        ]);
        $configFactory = new ConfigFactory($configStorage, $dispatcher);

        $resolver = new SingleConnectionResolver($db);
        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $db, $registry): EntityRepository {
            $schemaHandler = new SqlSchemaHandler($definition, $db, $registry);
            $schemaHandler->ensureTable();
            if ($definition->isRevisionable()) {
                $schemaHandler->ensureRevisionTable();
            }

            $idKey = $definition->getKeys()['id'] ?? 'id';

            return new EntityRepository(
                $definition,
                new SqlStorageDriver($resolver, $idKey),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $db,
                fieldRegistry: $registry,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory, fieldRegistry: $registry);
        ContentEntityBase::setFieldRegistry($registry);

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workflow',
            label: 'Workflow',
            class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'workflows',
        ));

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: Relationship::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'relationship_type', 'bundle' => 'relationship_type'],
            _fieldDefinitions: [
                'relationship_type' => ['type' => 'string', 'required' => true, 'weight' => 0],
                'from_entity_type' => ['type' => 'string', 'required' => true, 'weight' => 1],
                'from_entity_id' => ['type' => 'string', 'required' => true, 'weight' => 2],
                'to_entity_type' => ['type' => 'string', 'required' => true, 'weight' => 3],
                'to_entity_id' => ['type' => 'string', 'required' => true, 'weight' => 4],
                'directionality' => ['type' => 'string', 'weight' => 5, 'default' => 'directed'],
                'status' => ['type' => 'boolean', 'weight' => 6, 'default' => 1],
            ],
        ));

        $entityTypeManager->registerEntityType(new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Dept guard subject',
            class: GroupConstraintGuardSubject::class,
            keys: $this->entityKeys(),
            revisionable: true,
        ));

        $workflow = new Workflow([
            'id' => self::WORKFLOW_ID,
            'label' => 'Department routing (guard parity)',
            'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'In review'],
                'published' => ['label' => 'Published', 'published' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit for review', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['review'], 'to' => 'published', 'group_constraint' => 'content_groups'],
            ],
        ]);
        $workflow->enforceIsNew();
        $entityTypeManager->getRepository('workflow')->save($workflow);

        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);
        $membership = new GroupMembershipService($entityTypeManager);
        $checker = new GroupConstraintChecker($membership);
        $accountContext = new RequestAccountContext();

        $guard = new WorkflowStateGuard($bindings, $entityTypeManager, $accountContext, $checker);
        $dispatcher->addListener(EntityEvents::PRE_SAVE->value, [$guard, 'onPreSave']);

        return [$entityTypeManager, $accountContext];
    }

    private function addContentToGroup(EntityTypeManager $manager, string $entityId, string $groupId): void
    {
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => self::ENTITY_TYPE_ID,
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
     * @return array<string, string>
     */
    private function entityKeys(): array
    {
        return ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];
    }
}

final class GroupConstraintGuardSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
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

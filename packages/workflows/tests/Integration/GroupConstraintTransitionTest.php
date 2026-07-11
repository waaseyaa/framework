<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
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
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;

/**
 * Group-constraint gate integration spine (CW-v1 WP-3 T3,
 * docs/specs/content-workflow.md "Concepts and config schema" +
 * "TransitionService — the one door"): a test-local `dept` workflow with one
 * group-constrained transition (`publish`, `content_groups`) and two
 * constraint-less transitions (`submit_for_review`, `reject`), driven
 * through the REAL {@see TransitionService}, real {@see GroupConstraintChecker},
 * real {@see GroupMembershipService}, and real SQLite relationship storage —
 * membership fixtures inserted the same way
 * {@see \Waaseyaa\Groups\Tests\Integration\GroupMembershipServiceTest} does.
 *
 * Deny-path coverage is first-class per the spec's testing requirements:
 * non-member denial, fail-closed-on-no-group denial, and permission
 * precedence over the group gate are each their own test.
 */
#[CoversNothing]
final class GroupConstraintTransitionTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'dept_flow_subject';
    private const string WORKFLOW_ID = 'dept';
    private const string CONTENT_GROUP_ID = '10';
    private const string OTHER_GROUP_ID = '99';

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    #[Test]
    public function a_member_with_permission_may_fire_the_constrained_transition(): void
    {
        [$manager, $service] = $this->wireEngine();
        $entity = $this->createEntityInState($manager, 'review');
        $this->addContentToGroup($manager, (string) $entity->id(), self::CONTENT_GROUP_ID);
        $this->addUserToGroup($manager, '7', self::CONTENT_GROUP_ID);
        $account = $this->account(7, ['use dept transition publish']);

        $result = $service->transition($entity, 'publish', $account);

        $this->assertSame('published', $result->toState);
        $stored = $manager->getRepository(self::ENTITY_TYPE_ID)->find((string) $entity->id());
        $this->assertSame('published', $stored?->get('workflow_state'));
    }

    #[Test]
    public function a_non_member_with_permission_is_denied_and_state_stays_unchanged(): void
    {
        [$manager, $service] = $this->wireEngine();
        $entity = $this->createEntityInState($manager, 'review');
        $this->addContentToGroup($manager, (string) $entity->id(), self::CONTENT_GROUP_ID);
        // Account 8 belongs to a DIFFERENT group, not the content's group.
        $this->addUserToGroup($manager, '8', self::OTHER_GROUP_ID);
        $account = $this->account(8, ['use dept transition publish']);

        $denied = null;
        try {
            $service->transition($entity, 'publish', $account);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }

        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $denied->reason);

        $stored = $manager->getRepository(self::ENTITY_TYPE_ID)->find((string) $entity->id());
        $this->assertSame('review', $stored?->get('workflow_state'), 'A denied transition must not mutate persisted state.');
    }

    #[Test]
    public function content_with_no_recorded_group_denies_the_constrained_transition(): void
    {
        [$manager, $service] = $this->wireEngine();
        $entity = $this->createEntityInState($manager, 'review');
        // Deliberately no group_content relationship row for this entity.
        $this->addUserToGroup($manager, '7', self::CONTENT_GROUP_ID);
        $account = $this->account(7, ['use dept transition publish']);

        $denied = null;
        try {
            $service->transition($entity, 'publish', $account);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }

        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $denied->reason);
    }

    #[Test]
    public function a_constraint_less_transition_is_unaffected_by_group_membership(): void
    {
        [$manager, $service] = $this->wireEngine();

        $memberEntity = $this->createEntityInState($manager, 'draft');
        $nonMemberEntity = $this->createEntityInState($manager, 'draft');
        // Neither entity has any group_content row, and neither account has
        // any group_membership row — 'submit_for_review' carries no
        // group_constraint, so both succeed on permission alone.
        $memberAccount = $this->account(7, ['use dept transition submit_for_review']);
        $nonMemberAccount = $this->account(8, ['use dept transition submit_for_review']);

        $resultA = $service->transition($memberEntity, 'submit_for_review', $memberAccount);
        $resultB = $service->transition($nonMemberEntity, 'submit_for_review', $nonMemberAccount);

        $this->assertSame('review', $resultA->toState);
        $this->assertSame('review', $resultB->toState);
    }

    #[Test]
    public function permission_denial_fires_before_the_group_gate(): void
    {
        [$manager, $service] = $this->wireEngine();
        $entity = $this->createEntityInState($manager, 'review');
        $this->addContentToGroup($manager, (string) $entity->id(), self::CONTENT_GROUP_ID);
        // Account holds neither the transition's permission NOR membership.
        $account = $this->account(9, []);

        $denied = null;
        try {
            $service->transition($entity, 'publish', $account);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }

        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(
            TransitionDeniedException::REASON_PERMISSION,
            $denied->reason,
            'Permission denial must win over group-constraint denial (order pinned by spec).',
        );
    }

    #[Test]
    public function get_available_transitions_filters_the_constrained_transition_by_membership_only(): void
    {
        [$manager, $service] = $this->wireEngine();

        $entity = $this->createEntityInState($manager, 'review');
        $this->addContentToGroup($manager, (string) $entity->id(), self::CONTENT_GROUP_ID);
        $this->addUserToGroup($manager, '7', self::CONTENT_GROUP_ID);
        // Account 8 holds both permissions but is NOT a member of the content's group.
        $this->addUserToGroup($manager, '8', self::OTHER_GROUP_ID);

        $bothPermissions = ['use dept transition publish', 'use dept transition reject'];
        $member = $this->account(7, $bothPermissions);
        $nonMember = $this->account(8, $bothPermissions);

        $memberAvailable = \array_map(static fn($t) => $t->id, $service->getAvailableTransitions($entity, $member));
        $nonMemberAvailable = \array_map(static fn($t) => $t->id, $service->getAvailableTransitions($entity, $nonMember));

        \sort($memberAvailable);
        \sort($nonMemberAvailable);

        $this->assertSame(['publish', 'reject'], $memberAvailable, 'Member sees both the constrained and unconstrained transitions.');
        $this->assertSame(['reject'], $nonMemberAvailable, 'Non-member loses only the group-constrained transition; the unconstrained one is unaffected.');
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
     * @return array{0: EntityTypeManager, 1: TransitionService}
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
            label: 'Dept flow subject',
            class: GroupConstraintFlowSubject::class,
            keys: $this->entityKeys(),
            revisionable: true,
        ));

        $workflow = new Workflow([
            'id' => self::WORKFLOW_ID,
            'label' => 'Department routing',
            'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'In review'],
                'published' => ['label' => 'Published', 'published' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit for review', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['review'], 'to' => 'published', 'group_constraint' => 'content_groups'],
                'reject' => ['label' => 'Send back', 'from' => ['review'], 'to' => 'draft'],
            ],
        ]);
        $workflow->enforceIsNew();
        $entityTypeManager->getRepository('workflow')->save($workflow);

        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);
        $membership = new GroupMembershipService($entityTypeManager);
        $checker = new GroupConstraintChecker($membership);

        $service = new TransitionService(
            bindings: $bindings,
            entityTypeManager: $entityTypeManager,
            dispatcher: $dispatcher,
            groupConstraintChecker: $checker,
        );

        return [$entityTypeManager, $service];
    }

    private function createEntityInState(EntityTypeManager $manager, string $state): GroupConstraintFlowSubject
    {
        $entity = new GroupConstraintFlowSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => $state, 'status' => 0],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $manager->getRepository(self::ENTITY_TYPE_ID)->save($entity);

        return $entity;
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

final class GroupConstraintFlowSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
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

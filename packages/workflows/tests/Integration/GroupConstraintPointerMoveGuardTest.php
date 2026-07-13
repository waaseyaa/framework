<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Group\GroupConstraintChecker;
use Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;

/**
 * CW-v1 WP-3 T4 (#1920, docs/specs/content-workflow.md "Pointer-move guard"
 * + "Pointer-move guard reconciliation"): group-constraint parity on
 * {@see \Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard}, both
 * branches of the two-branch rule. Events are constructed directly, the same
 * unit-style {@see WorkflowPointerMoveGuardTest} already uses (standing up
 * full two-axis revisionable storage for every scenario here would be
 * disproportionate — integration-grade coverage of the full stack is T5's
 * job) — but the {@see GroupConstraintChecker} wired in is REAL, backed by a
 * REAL {@see GroupMembershipService} over REAL SQLite `relationship` storage
 * (the same fixture shape T3's `GroupConstraintTransitionTest` and this
 * task's `GroupConstraintSaveGuardTest` use), so the group-membership
 * lookups this test exercises are not stubbed.
 */
#[CoversNothing]
final class GroupConstraintPointerMoveGuardTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'fixture';
    private const string CONTENT_GROUP_ID = '10';
    private const string OTHER_GROUP_ID = '99';

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    #[Test]
    public function different_state_move_across_a_constrained_edge_is_denied_for_a_non_member(): void
    {
        $checker = $this->realChecker();
        $this->addContentToGroup('1', self::CONTENT_GROUP_ID);
        // Account 8 belongs to a DIFFERENT group, not the content's group.
        $this->addUserToGroup('8', self::OTHER_GROUP_ID);

        $guard = $this->guard([], $this->account(8, ['use dept transition publish']), $checker);
        $event = $this->publishEvent();

        $denied = null;
        try {
            $guard->onBeforePointerMove($event);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }

        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $denied->reason);
    }

    #[Test]
    public function different_state_move_across_a_constrained_edge_is_allowed_for_a_member(): void
    {
        $checker = $this->realChecker();
        $this->addContentToGroup('1', self::CONTENT_GROUP_ID);
        $this->addUserToGroup('7', self::CONTENT_GROUP_ID);

        $guard = $this->guard([], $this->account(7, ['use dept transition publish']), $checker);
        $event = $this->publishEvent();

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function same_state_move_denies_a_non_member_when_the_only_candidate_into_the_state_is_constrained(): void
    {
        $checker = $this->realChecker();
        $this->addContentToGroup('1', self::CONTENT_GROUP_ID);
        $this->addUserToGroup('8', self::OTHER_GROUP_ID);

        // Account holds ONLY the constrained transition's permission, no
        // membership, and does NOT hold 'restore_to_published' (the other
        // transition into 'published') — the any-of loop has exactly one
        // candidate and it fails on the group constraint.
        $guard = $this->guard([10 => 'published'], $this->account(8, ['use dept transition publish']), $checker);
        $event = $this->samePublishedStateEvent();

        $denied = null;
        try {
            $guard->onBeforePointerMove($event);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }

        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);
    }

    #[Test]
    public function same_state_move_allows_a_member_via_the_constrained_candidate(): void
    {
        $checker = $this->realChecker();
        $this->addContentToGroup('1', self::CONTENT_GROUP_ID);
        $this->addUserToGroup('7', self::CONTENT_GROUP_ID);

        $guard = $this->guard([10 => 'published'], $this->account(7, ['use dept transition publish']), $checker);
        $event = $this->samePublishedStateEvent();

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function same_state_move_allows_a_non_member_via_a_constraint_less_second_candidate(): void
    {
        // Pin: the any-of semantics are unchanged for a constraint-less
        // transition — it keeps counting on permission alone even though
        // ANOTHER transition into the same state ('publish') is
        // group-constrained and this account satisfies neither its
        // permission nor its constraint.
        $checker = $this->realChecker();
        $this->addContentToGroup('1', self::CONTENT_GROUP_ID);
        $this->addUserToGroup('8', self::OTHER_GROUP_ID);

        $guard = $this->guard([10 => 'published'], $this->account(8, ['use dept transition restore_to_published']), $checker);
        $event = $this->samePublishedStateEvent();

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function null_account_context_is_unaffected_by_the_group_constraint_on_a_different_state_move(): void
    {
        // Pin: null context stays edge-legality-only, unchanged by WP-3 —
        // no group_content / group_membership rows recorded anywhere, and
        // the move still passes because there is no account to check
        // permission (or group constraint) against.
        $checker = $this->realChecker();

        $entityTypeManager = $this->fixtureEntityTypeManager($this->workflow(), []);
        $guard = new WorkflowPointerMoveGuard(
            $this->bindings($this->workflow(), $entityTypeManager),
            $entityTypeManager,
            null,
            $checker,
        );
        $event = $this->publishEvent();

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function null_account_context_is_unaffected_by_the_group_constraint_on_a_same_state_move(): void
    {
        $checker = $this->realChecker();

        $entityTypeManager = $this->fixtureEntityTypeManager($this->workflow(), [10 => 'published']);
        $guard = new WorkflowPointerMoveGuard(
            $this->bindings($this->workflow(), $entityTypeManager),
            $entityTypeManager,
            null,
            $checker,
        );
        $event = $this->samePublishedStateEvent();

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    private function workflow(): Workflow
    {
        return new Workflow(['id' => 'dept', 'label' => 'Department routing', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'In review'],
                'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
                'archived' => ['label' => 'Archived', 'published' => false, 'default_revision' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published', 'group_constraint' => 'content_groups'],
                'archive' => ['label' => 'Archive', 'from' => ['published'], 'to' => 'archived'],
                'restore_to_published' => ['label' => 'Restore', 'from' => ['archived'], 'to' => 'published'],
            ],
        ]);
    }

    private function publishEvent(): BeforeRevisionPointerMoveEvent
    {
        // fromRevisionId null => currentlyEffectiveState() falls back to the
        // workflow's initial state ('draft') => different-state move
        // draft -> published, matched by the group-constrained 'publish' edge.
        return new BeforeRevisionPointerMoveEvent(
            entityTypeId: self::ENTITY_TYPE_ID,
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );
    }

    private function samePublishedStateEvent(): BeforeRevisionPointerMoveEvent
    {
        // fromRevisionId 10 resolves (via the revisionStates map) to
        // 'published', matching the target state => same-state move.
        return new BeforeRevisionPointerMoveEvent(
            entityTypeId: self::ENTITY_TYPE_ID,
            entityId: '1',
            operation: 'rollback',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );
    }

    /**
     * @param array<int, ?string> $revisionStates
     */
    private function guard(array $revisionStates, ?AccountInterface $account, GroupConstraintChecker $checker): WorkflowPointerMoveGuard
    {
        $workflow = $this->workflow();
        $entityTypeManager = $this->fixtureEntityTypeManager($workflow, $revisionStates);

        return new WorkflowPointerMoveGuard(
            $this->bindings($workflow, $entityTypeManager),
            $entityTypeManager,
            $this->accountContext($account),
            $checker,
        );
    }

    /**
     * Hand-rolled fixture EntityTypeManager for the guard's OWN needs
     * (bundle resolution, `loadRevision()` for `currentlyEffectiveState()`)
     * — mirrors {@see WorkflowPointerMoveGuardTest}'s fixture exactly. This
     * is deliberately a DIFFERENT EntityTypeManager instance than the real
     * one backing {@see GroupMembershipService} below: the checker's
     * membership lookups go through real SQLite `relationship` storage, the
     * guard's own edge/state resolution stays a cheap in-memory fixture.
     *
     * @param array<int, ?string> $revisionStates revisionId => workflow_state (absent key => missing revision)
     */
    private function fixtureEntityTypeManager(?Workflow $workflow, array $revisionStates): EntityTypeManagerInterface
    {
        return new class ($workflow, $revisionStates) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly ?Workflow $workflow,
                private readonly array $revisionStates,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(
                    id: 'fixture',
                    label: 'Fixture',
                    class: \stdClass::class,
                    keys: ['id' => 'id', 'bundle' => 'type', 'revision' => 'vid'],
                    revisionable: true,
                );
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }

            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                throw new \LogicException('not needed: production getStorage() has no storageFactory (C-22 WP4)');
            }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $workflow = $this->workflow;
                $revisionStates = $this->revisionStates;

                return new class ($workflow, $revisionStates) implements EntityRepositoryInterface {
                    public function __construct(
                        private readonly ?Workflow $workflow,
                        private readonly array $revisionStates,
                    ) {}

                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflow; }
                    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(string $id): bool { return true; }
                    public function count(array $criteria = []): int { return 0; }

                    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
                    {
                        if (!\array_key_exists($revisionId, $this->revisionStates)) {
                            return null;
                        }

                        $state = $this->revisionStates[$revisionId];

                        return new class ($state) implements EntityInterface {
                            public function __construct(private readonly ?string $state) {}
                            public function id(): int|string|null { return 1; }
                            public function uuid(): string { return 'u-1'; }
                            public function label(): string { return 'Fixture'; }
                            public function getEntityTypeId(): string { return 'fixture'; }
                            public function bundle(): string { return 'article'; }
                            public function isNew(): bool { return false; }
                            public function get(string $name): mixed { return $name === 'workflow_state' ? $this->state : null; }
                            public function set(string $name, mixed $value): static { return $this; }
                            public function toArray(): array { return []; }
                            public function language(): string { return 'en'; }
                        };
                    }

                    public function rollback(string $entityId, int $targetRevisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function listRevisions(string $entityId): array { return []; }
                    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function loadPublishedRevision(string $entityId): ?EntityInterface { return null; }
                    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface { throw new \LogicException('not needed'); }
                    public function saveMany(array $entities, bool $validate = true): array { return []; }
                    public function deleteMany(array $entities): int { return 0; }
                    public function findTranslations(EntityInterface $entity): array { return []; }
                    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int { return 0; }
                    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface { return null; }
                    public function listTranslationRevisions(string $entityId, string $langcode): array { return []; }
                };
            }
        };
    }

    private function bindings(?Workflow $workflow, EntityTypeManagerInterface $entityTypeManager): WorkflowBindingResolver
    {
        $configFactory = new class ($workflow) implements ConfigFactoryInterface {
            public function __construct(private readonly ?Workflow $workflow) {}

            public function get(string $name): ConfigInterface
            {
                $data = $this->workflow !== null ? ['fixture.article' => 'dept'] : [];

                return new class ($data) implements ConfigInterface {
                    public function __construct(private readonly array $data) {}
                    public function getName(): string { return 'workflows.assignments'; }
                    public function get(string $key = ''): mixed { return $key === '' ? $this->data : ($this->data[$key] ?? null); }
                    public function set(string $key, mixed $value): static { return $this; }
                    public function clear(string $key): static { return $this; }
                    public function delete(): static { return $this; }
                    public function save(): static { return $this; }
                    public function isNew(): bool { return $this->data === []; }
                    public function getRawData(): array { return $this->data; }
                };
            }

            public function getEditable(string $name): ConfigInterface { return $this->get($name); }
            public function loadMultiple(array $names): array { return []; }
            public function rename(string $oldName, string $newName): static { return $this; }
            public function listAll(string $prefix = ''): array { return []; }
        };

        return new WorkflowBindingResolver($configFactory, $entityTypeManager);
    }

    private function accountContext(?AccountInterface $account): \Waaseyaa\Access\Context\AccountContextInterface
    {
        return new class ($account) implements \Waaseyaa\Access\Context\AccountContextInterface {
            public function __construct(private readonly ?AccountInterface $account) {}
            public function current(): ?AccountInterface { return $this->account; }
            public function set(?AccountInterface $account): void {}
        };
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
     * Real {@see GroupConstraintChecker} over real {@see GroupMembershipService}
     * over real SQLite `relationship` storage — a fresh instance (and fresh
     * database) per call, so each test's group/membership fixtures are
     * isolated.
     */
    private function realChecker(): GroupConstraintChecker
    {
        $manager = $this->relationshipEntityTypeManager();
        $this->relationshipManager = $manager;

        return new GroupConstraintChecker(new GroupMembershipService($manager));
    }

    private ?EntityTypeManager $relationshipManager = null;

    private function relationshipEntityTypeManager(): EntityTypeManager
    {
        EntityType::clearFromClassCache();
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registry = new FieldDefinitionRegistry();
        $db = DBALDatabase::createSqlite();
        $resolver = new SingleConnectionResolver($db);

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $db, $registry): EntityRepository {
            $schemaHandler = new SqlSchemaHandler($definition, $db, $registry);
            $schemaHandler->ensureTable();

            $idKey = $definition->getKeys()['id'] ?? 'id';

            return new EntityRepository(
                $definition,
                new SqlStorageDriver($resolver, $idKey),
                $dispatcher,
                null,
                $db,
                fieldRegistry: $registry,
            );
        };

        $manager = new EntityTypeManager($dispatcher, null, $repositoryFactory, fieldRegistry: $registry);
        ContentEntityBase::setFieldRegistry($registry);

        $manager->registerEntityType(new EntityType(
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

        return $manager;
    }

    private function addContentToGroup(string $entityId, string $groupId): void
    {
        $this->createRelationship([
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => self::ENTITY_TYPE_ID,
            'from_entity_id' => $entityId,
            'to_entity_type' => 'group',
            'to_entity_id' => $groupId,
        ]);
    }

    private function addUserToGroup(string $uid, string $groupId): void
    {
        $this->createRelationship([
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
    private function createRelationship(array $values): void
    {
        \assert($this->relationshipManager instanceof EntityTypeManager);
        $repository = $this->relationshipManager->getRepository('relationship');
        $entity = $repository->create($values + ['directionality' => 'directed', 'status' => 1]);
        $repository->save($entity, validate: false);
    }
}

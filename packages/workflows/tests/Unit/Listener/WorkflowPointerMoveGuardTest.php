<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;

/**
 * @covers \Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard
 */
#[CoversClass(WorkflowPointerMoveGuard::class)]
final class WorkflowPointerMoveGuardTest extends TestCase
{
    private function editorialWorkflow(): Workflow
    {
        return new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published'],
            ],
        ]);
    }

    /**
     * @param array<int, ?string> $revisionStates revisionId => workflow_state (absent key => missing revision)
     */
    private function entityTypeManager(?Workflow $workflow, array $revisionStates = []): EntityTypeManagerInterface
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
                $data = $this->workflow !== null ? ['fixture.article' => 'editorial'] : [];

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

    private function accountContext(?AccountInterface $account): AccountContextInterface
    {
        return new class ($account) implements AccountContextInterface {
            public function __construct(private readonly ?AccountInterface $account) {}
            public function current(): ?AccountInterface { return $this->account; }
            public function set(?AccountInterface $account): void {}
        };
    }

    private function account(array $permissions): AccountInterface
    {
        return new class ($permissions) implements AccountInterface {
            public function __construct(private readonly array $permissions) {}
            public function id(): int|string { return 7; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
        };
    }

    /**
     * @param array<int, ?string> $revisionStates
     */
    private function guard(?Workflow $workflow, array $revisionStates, ?AccountInterface $account): WorkflowPointerMoveGuard
    {
        $entityTypeManager = $this->entityTypeManager($workflow, $revisionStates);

        return new WorkflowPointerMoveGuard(
            $this->bindings($workflow, $entityTypeManager),
            $entityTypeManager,
            $this->accountContext($account),
        );
    }

    #[Test]
    public function unbound_entity_type_is_untouched(): void
    {
        $guard = $this->guard(null, [], $this->account(['use editorial transition publish']));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 5,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'nonexistent'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function allowed_edge_passes(): void
    {
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'draft'], $account);
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function missing_edge_is_denied(): void
    {
        // 'published' -> 'review' has no transition in the fixture workflow.
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'published'], $this->account(['use editorial transition publish']));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'revert',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'review'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $e->reason);
        }
    }

    #[Test]
    public function permission_denied_with_account(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'draft'], $this->account([]));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        try {
            $guard->onBeforePointerMove($event);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    #[Test]
    public function null_account_context_checks_edge_legality_only(): void
    {
        $entityTypeManager = $this->entityTypeManager($this->editorialWorkflow(), [10 => 'draft']);
        $guard = new WorkflowPointerMoveGuard(
            $this->bindings($this->editorialWorkflow(), $entityTypeManager),
            $entityTypeManager,
            null,
        );
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: null,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function translation_save_is_always_a_pass_through(): void
    {
        // Even with an implied illegal edge and no permission, translation_save
        // never validates a transition — v1 workflow_state is per revision, not
        // per revision-translation (docs/specs/content-workflow.md).
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'published'], $this->account([]));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'translation_save',
            fromRevisionId: 10,
            toRevisionId: null,
            actorUid: 7,
            revisionValues: ['some_field' => 'translated value'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function null_from_revision_id_falls_back_to_the_initial_state(): void
    {
        // 'publish' with fromRevisionId null (previously unpublished): the
        // currently-effective state falls back to the workflow's initial state.
        $guard = $this->guard($this->editorialWorkflow(), [], $this->account(['use editorial transition publish']));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'published'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function no_implied_state_change_is_a_no_op_even_without_permission(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), [10 => 'draft'], $this->account([]));
        $event = new BeforeRevisionPointerMoveEvent(
            entityTypeId: 'fixture',
            entityId: '1',
            operation: 'revert',
            fromRevisionId: 10,
            toRevisionId: 20,
            actorUid: 7,
            revisionValues: ['type' => 'article', 'workflow_state' => 'draft'],
        );

        $guard->onBeforePointerMove($event);
        $this->addToAssertionCount(1);
    }
}

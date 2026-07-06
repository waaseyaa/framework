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
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Listener\WorkflowStateGuard;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;

/**
 * @covers \Waaseyaa\Workflows\Listener\WorkflowStateGuard
 */
#[CoversClass(WorkflowStateGuard::class)]
final class WorkflowStateGuardTest extends TestCase
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

    private function bindings(?Workflow $workflow): WorkflowBindingResolver
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

        $entityTypeManager = new class ($workflow) implements EntityTypeManagerInterface {
            public function __construct(private readonly ?Workflow $workflow) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(id: 'fixture', label: 'Fixture', class: \stdClass::class, keys: ['id' => 'id', 'revision' => 'vid'], revisionable: true);
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }

            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed: production getStorage() has no storageFactory (C-22 WP4)'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                $workflow = $this->workflow;

                return new class ($workflow) implements EntityRepositoryInterface {
                    public function __construct(private readonly ?Workflow $workflow) {}
                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflow; }
                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(string $id): bool { return $this->workflow !== null; }
                    public function count(array $criteria = []): int { return 0; }
                    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface { return null; }
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

    /** @param array<string, mixed> $values */
    private function entity(array $values, bool $isNew): EntityInterface
    {
        return new class ($values, $isNew) implements EntityInterface {
            public function __construct(private array $values, private readonly bool $new) {}
            public function id(): int|string|null { return $this->values['id'] ?? null; }
            public function uuid(): string { return 'u-1'; }
            public function label(): string { return 'Fixture'; }
            public function getEntityTypeId(): string { return 'fixture'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return $this->new; }
            public function get(string $name): mixed { return $this->values[$name] ?? null; }

            public function set(string $name, mixed $value): static
            {
                $this->values[$name] = $value;

                return $this;
            }

            public function toArray(): array { return $this->values; }
            public function language(): string { return 'en'; }
        };
    }

    private function guard(Workflow $workflow, ?AccountInterface $account): WorkflowStateGuard
    {
        return new WorkflowStateGuard($this->bindings($workflow), $this->accountContext($account));
    }

    #[Test]
    public function unbound_entities_are_untouched(): void
    {
        $guard = new WorkflowStateGuard($this->bindings(null));
        $entity = $this->entity(['id' => 1], isNew: true);

        $guard->onPreSave(new EntityEvent($entity));

        $this->assertNull($entity->get('workflow_state'));
    }

    #[Test]
    public function create_without_workflow_state_forces_initial_state_and_status(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), null);
        $entity = $this->entity(['id' => 1], isNew: true);

        $guard->onPreSave(new EntityEvent($entity));

        $this->assertSame('draft', $entity->get('workflow_state'));
        $this->assertSame(0, $entity->get('status'));
    }

    #[Test]
    public function create_explicitly_in_initial_state_is_allowed(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), null);
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: true);

        $guard->onPreSave(new EntityEvent($entity));

        $this->assertSame('draft', $entity->get('workflow_state'));
        $this->assertSame(0, $entity->get('status'));
    }

    #[Test]
    public function create_born_published_is_allowed_with_a_permitted_account(): void
    {
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->guard($this->editorialWorkflow(), $account);
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: true);

        $guard->onPreSave(new EntityEvent($entity));

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function create_born_published_is_denied_without_permission(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), $this->account([]));
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: true);

        try {
            $guard->onPreSave(new EntityEvent($entity));
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    #[Test]
    public function create_born_published_is_denied_with_a_null_account_context(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), null);
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: true);

        try {
            $guard->onPreSave(new EntityEvent($entity));
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    #[Test]
    public function create_in_an_unreachable_state_is_denied_with_illegal_edge(): void
    {
        // 'review' is not reachable from 'draft' in a single hop for a plain
        // create (only submit_for_review from draft goes TO review — this IS
        // reachable actually; use a state with no incoming transition from
        // initial to prove the illegal-edge branch). We add a workflow whose
        // 'archived' state has no transition FROM 'draft'.
        $workflow = $this->editorialWorkflow();
        $guard = $this->guard($workflow, $this->account(['use editorial transition publish']));
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'nonexistent'], isNew: true);

        try {
            $guard->onPreSave(new EntityEvent($entity));
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $e->reason);
        }
    }

    #[Test]
    public function update_with_unchanged_state_forces_status_consistency(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), null);
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 0], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function update_with_a_legal_permitted_transition_is_allowed(): void
    {
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->guard($this->editorialWorkflow(), $account);
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function update_with_no_matching_transition_is_denied_with_illegal_edge(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition publish']));
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        try {
            $guard->onPreSave(new EntityEvent($entity, $original));
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $e->reason);
        }
    }

    #[Test]
    public function update_with_a_legal_transition_but_no_permission_is_denied(): void
    {
        $guard = $this->guard($this->editorialWorkflow(), $this->account([]));
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        try {
            $guard->onPreSave(new EntityEvent($entity, $original));
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }
    }

    #[Test]
    public function update_with_a_null_account_context_checks_edge_legality_only(): void
    {
        // CLI/queue/programmatic: no acting context, so permission cannot be
        // proven — the guard falls back to edge-legality only (rule 3).
        $guard = $this->guard($this->editorialWorkflow(), null);
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
    }
}

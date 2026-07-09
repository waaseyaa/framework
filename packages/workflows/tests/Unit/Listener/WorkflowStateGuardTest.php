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
                // Test-only edge (not in the production DefaultWorkflows
                // seed): lets forward-draft tests exercise "raw-save an
                // already-published entity back into a non-default-revision
                // state" without touching production config.
                'revise' => ['label' => 'Revise', 'from' => ['published'], 'to' => 'draft'],
            ],
        ]);
    }

    private function entityTypeManager(?Workflow $workflow, ?EntityInterface $publishedRevision = null): EntityTypeManagerInterface
    {
        return new class ($workflow, $publishedRevision) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly ?Workflow $workflow,
                private readonly ?EntityInterface $publishedRevision,
            ) {}

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
                $publishedRevision = $this->publishedRevision;

                return new class ($workflow, $publishedRevision) implements EntityRepositoryInterface {
                    public function __construct(
                        private readonly ?Workflow $workflow,
                        private readonly ?EntityInterface $publishedRevision,
                    ) {}
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
                    public function loadPublishedRevision(string $entityId): ?EntityInterface { return $this->publishedRevision; }
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

    private function guard(Workflow $workflow, ?AccountInterface $account, ?EntityInterface $publishedRevision = null): WorkflowStateGuard
    {
        $entityTypeManager = $this->entityTypeManager($workflow, $publishedRevision);

        return new WorkflowStateGuard(
            $this->bindings($workflow, $entityTypeManager),
            $entityTypeManager,
            $this->accountContext($account),
        );
    }

    #[Test]
    public function unbound_entities_are_untouched(): void
    {
        $entityTypeManager = $this->entityTypeManager(null);
        $guard = new WorkflowStateGuard($this->bindings(null, $entityTypeManager), $entityTypeManager);
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
        // 'review' -> 'draft' has no edge in the fixture (only
        // submit_for_review draft->review, publish draft/review->published,
        // and the test-only revise published->draft exist).
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition publish']));
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'review'], isNew: false);

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

    #[Test]
    public function forward_draft_on_already_published_content_preserves_the_published_status(): void
    {
        // CW-v1 WP-2 task 2.6 (#1920, two-pointer status semantics): a raw
        // save that moves an already-published entity into a
        // `default_revision: false` state (here 'draft', via the test-only
        // 'revise' edge) must NOT flip `status` to match the target state's
        // `published` flag (false => 0) — the published pointer is
        // untouched by this guard, so `status` must keep reflecting the
        // PUBLISHED revision (status = 1), not this new non-live tip.
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition revise']), $publishedRevision);

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('draft', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function non_default_revision_state_with_no_prior_publish_follows_the_state_directly(): void
    {
        // No published pointer exists yet (never-published content): WP-1
        // behavior stands — status follows the target state directly, there
        // is nothing live to protect.
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition submit_for_review']));

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'review'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('review', $entity->get('workflow_state'));
        $this->assertSame(0, $entity->get('status'));
    }

    #[Test]
    public function entering_a_default_revision_state_still_flips_status_directly(): void
    {
        // Target state IS `default_revision: true` ('published') — this
        // guard cannot move the published pointer itself (PRE_SAVE fires
        // before the new revision id exists), so it keeps WP-1 behavior:
        // status follows the target state's `published` flag. Whether the
        // pointer itself should also move is TransitionService's job
        // (task 2.6) when the caller goes through the "one door"; a raw
        // save into a default-revision state is a documented, narrower
        // guarantee (see WorkflowStateGuard class docblock).
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'draft', 'status' => 0], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition publish']), $publishedRevision);

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'review'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
    }
}

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
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Group\GroupConstraintChecker;
use Waaseyaa\Workflows\Listener\WorkflowStateGuard;
use Waaseyaa\Workflows\Republish\RepublishMarker;
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
                // A test-local published -> draft edge (the shipped
                // `DefaultWorkflows::EDITORIAL` no longer ships one — WP-2
                // rework, forward drafts deferred): lets forward-draft tests
                // exercise "raw-save an already-published entity back into a
                // non-default-revision state" against the engine directly,
                // independent of what the shipped workflow exposes.
                'revise' => ['label' => 'Revise', 'from' => ['published'], 'to' => 'draft'],
            ],
        ]);
    }

    /**
     * @param bool $workingCopyExplicitlyNull true means "loadWorkingCopy() must
     *   return null" (proving guardUpdate()'s fallback to $originalEntity);
     *   false (default) means "no override" — loadWorkingCopy() mirrors find(),
     *   which returns $workingCopy when given, else null.
     */
    private function entityTypeManager(
        ?Workflow $workflow,
        ?EntityInterface $publishedRevision = null,
        ?EntityInterface $workingCopy = null,
    ): EntityTypeManagerInterface {
        return new class ($workflow, $publishedRevision, $workingCopy) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly ?Workflow $workflow,
                private readonly ?EntityInterface $publishedRevision,
                private readonly ?EntityInterface $workingCopy,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(id: 'fixture', label: 'Fixture', class: \stdClass::class, keys: ['id' => 'id', 'revision' => 'vid'], revisionable: true, revisionDefault: true);
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }

            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed: production getStorage() has no storageFactory (C-22 WP4)'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                // `WorkflowBindingResolver` looks up the bound Workflow
                // itself via getRepository('workflow')->find($workflowId) —
                // kept as a distinct branch so that lookup stays independent
                // of the guard's OWN entity repository (used for
                // loadPublishedRevision()/loadWorkingCopy() below), which
                // this fixture previously conflated (both calls hit the
                // same repository double, so loadWorkingCopy() returned the
                // bound Workflow object instead of a content-entity stand-in
                // — CW-v1 option-1 #1920 PR-2 fix).
                if ($entityTypeId === 'workflow') {
                    $workflow = $this->workflow;

                    return new class ($workflow) implements EntityRepositoryInterface {
                        public function __construct(private readonly ?Workflow $workflow) {}
                        public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                        public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflow; }
                        public function loadWorkingCopy(string $id): ?EntityInterface { return $this->find($id); }
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

                $publishedRevision = $this->publishedRevision;
                $workingCopy = $this->workingCopy;

                return new class ($publishedRevision, $workingCopy) implements EntityRepositoryInterface {
                    public function __construct(
                        private readonly ?EntityInterface $publishedRevision,
                        private readonly ?EntityInterface $workingCopy,
                    ) {}
                    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
                    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workingCopy; }
                    public function loadWorkingCopy(string $id): ?EntityInterface { return $this->workingCopy; }
                    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
                    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
                    public function getQuery(): EntityQueryInterface { throw new \LogicException('not needed'); }
                    public function save(EntityInterface $entity, bool $validate = true): int { throw new \LogicException('not needed'); }
                    public function delete(EntityInterface $entity): void {}
                    public function exists(string $id): bool { return true; }
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

    /**
     * A fixture entity that also carries the legacy RevisionableInterface
     * revision knobs, for the forced-new-revision tests (task 2.6 panel
     * fix B).
     *
     * @param array<string, mixed> $values
     *
     * @return EntityInterface&RevisionableInterface
     */
    private function revisionableEntity(array $values, bool $isNew): EntityInterface&RevisionableInterface
    {
        return new class ($values, $isNew) implements EntityInterface, RevisionableInterface {
            private ?bool $newRevisionOverride = null;
            private ?string $revisionLog = null;

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

            public function getRevisionId(): ?int
            {
                $rid = $this->values['revision_id'] ?? null;

                return \is_int($rid) ? $rid : null;
            }

            public function isDefaultRevision(): bool { return true; }
            public function isLatestRevision(): bool { return true; }
            public function setNewRevision(bool $value): void { $this->newRevisionOverride = $value; }
            public function isNewRevision(): ?bool { return $this->newRevisionOverride; }
            public function setRevisionLog(?string $log): void { $this->revisionLog = $log; }
            public function getRevisionLog(): ?string { return $this->revisionLog; }
        };
    }

    /**
     * A fixture entity built on the REAL {@see RevisionableEntityTrait} —
     * carries `setDefaultRevisionDiscipline()`/`isDefaultRevisionDisciplined()`
     * (the actual production discipline-flag storage, not a hand-rolled
     * stand-in), so discipline-flag tests exercise the exact duck-checked
     * `method_exists()` gate the guard uses.
     *
     * @param array<string, mixed> $values
     */
    private function disciplinedEntity(array $values, bool $isNew): EntityInterface&RevisionableEntityInterface
    {
        return new class ($values, $isNew) implements EntityInterface, RevisionableEntityInterface {
            use RevisionableEntityTrait;

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

    /**
     * Full-collaborator guard for the discipline-flag / working-copy-basis /
     * same-state-republish tests (CW-v1 option-1, #1920 PR-2).
     */
    private function fullGuard(
        Workflow $workflow,
        ?AccountInterface $account,
        ?EntityInterface $publishedRevision,
        ?EntityInterface $workingCopy,
        RepublishMarker $republishMarker,
        ?GroupConstraintChecker $groupConstraintChecker = null,
    ): WorkflowStateGuard {
        $entityTypeManager = $this->entityTypeManager($workflow, $publishedRevision, $workingCopy);

        return new WorkflowStateGuard(
            $this->bindings($workflow, $entityTypeManager),
            $entityTypeManager,
            $this->accountContext($account),
            $groupConstraintChecker,
            $republishMarker,
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
    public function entering_a_default_revision_state_on_a_pointered_entity_leaves_status_riding_the_pointer(): void
    {
        // CW-v1 WP-2 task 2.6 panel fix A (#1920): the guard must NEVER
        // derive `status` from the target state once a published pointer
        // exists — TransitionService fires this guard on its FIRST
        // (revision-creating) save and deliberately defers the status flip
        // until after setPublishedRevision() succeeds; a guard-side
        // target-state derivation here committed the flip before the
        // pointer move could be denied. Status rides the pointer: derived
        // from the pointer revision's own state ('draft' → published flag
        // false → 0), even though the save is entering 'published'.
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'draft', 'status' => 0], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition publish']), $publishedRevision);

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'review'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(0, $entity->get('status'), 'Guard must not flip status from the target state while the pointer sits on an unpublished-state revision.');
    }

    #[Test]
    public function entering_a_default_revision_state_on_a_never_published_entity_follows_the_state(): void
    {
        // Never-published: WP-1 status-follows-state stands (no pointer to
        // ride) — the counterpart to the pointered test above.
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition publish']));

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'review'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function status_derives_from_the_pointer_revisions_state_not_its_stored_status_column(): void
    {
        // The pointer revision's stored `status` column can be stale
        // relative to its state during TransitionService's post-pointer-move
        // status-flip save (the flip is what corrects it). Deriving from
        // the STATE keeps the guard consistent with the flip; copying the
        // stored column would overwrite it.
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 0], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), null, $publishedRevision);

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        // Pointer state 'published' → derived 1, regardless of the stale
        // stored status 0 on the pointer row.
        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function a_forward_draft_forces_a_new_revision_overriding_the_bundle_opt_out(): void
    {
        // CW-v1 WP-2 task 2.6 panel fix B (#1920): a state-changing save
        // into a defaultRevision:false state on a pointered entity is a
        // forward draft — it REQUIRES a new revision, or a
        // new_revision:false bundle would update the published revision in
        // place (live content corruption). The guard's set is unconditional
        // and overrides even an explicit earlier setNewRevision(false) —
        // this is what makes the outcome identical in both orders relative
        // to NodeRevisionDefaultListener (which respects non-null values).
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition revise']), $publishedRevision);

        $entity = $this->revisionableEntity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $entity->setNewRevision(false); // simulated bundle opt-out (listener-first order)
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertTrue($entity->isNewRevision(), 'Forward draft must force a new revision even over an explicit opt-out.');
        $this->assertSame('draft', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
    }

    #[Test]
    public function a_non_state_changing_save_does_not_force_a_new_revision(): void
    {
        // The bundle opt-out governs ordinary edits: an unchanged
        // workflow_state leaves the revision decision untouched (null =
        // "use the bundle/type default").
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), null, $publishedRevision);

        $entity = $this->revisionableEntity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertNull($entity->isNewRevision(), 'A non-state-changing edit must leave the bundle opt-out in force.');
    }

    #[Test]
    public function a_state_changing_save_into_a_default_revision_state_is_also_forced_to_a_new_revision(): void
    {
        // Verifier residual (task 2.6): the forced-revision rule is UNIFORM
        // — a raw save into a defaultRevision:true state ('published' here,
        // via the publish edge from review) on a pointered entity forces a
        // new revision too, otherwise an opt-out bundle would write the new
        // state into the pointer-served row in place. Raw saves never enact
        // pointer moves; the result is an unpromoted tip.
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition publish']), $publishedRevision);

        $entity = $this->revisionableEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $entity->setNewRevision(false); // simulated bundle opt-out
        $original = $this->entity(['id' => 1, 'workflow_state' => 'review'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertTrue($entity->isNewRevision(), 'A state-changing save into a default-revision state on a pointered entity must also force a new revision.');
    }

    #[Test]
    public function a_pointer_revision_with_no_workflow_state_falls_back_to_its_stored_published_status(): void
    {
        // Legacy fallback (pre-backfill data): the pointer revision carries
        // NO workflow_state, so its state cannot be mapped through the
        // workflow's published flag — the stored status column is preserved
        // as-is (here 1), never derived from the save's target state
        // ('draft', published flag false).
        $publishedRevision = $this->entity(['id' => 1, 'status' => 1], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition revise']), $publishedRevision);

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame(1, $entity->get('status'));
    }

    private function workflowWithGroupConstraint(): Workflow
    {
        return new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'review' => ['label' => 'Review'],
                'published' => ['label' => 'Published', 'published' => true],
            ],
            'transitions' => [
                'submit_for_review' => ['label' => 'Submit', 'from' => ['draft'], 'to' => 'review'],
                'publish' => ['label' => 'Publish', 'from' => ['draft', 'review'], 'to' => 'published', 'group_constraint' => 'content_groups'],
            ],
        ]);
    }

    #[Test]
    public function a_null_checker_denies_a_group_constrained_update_fail_closed_when_an_account_context_exists(): void
    {
        // Adversarial-review fix (#1920, WP-3): a null groupConstraintChecker
        // used to mean "no group gating" for a group_constrained transition
        // (fail-open). It must fail closed when an acting account context
        // exists — same rule as TransitionService.
        $workflow = $this->workflowWithGroupConstraint();
        $guard = $this->guard($workflow, $this->account(['use editorial transition publish']));
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        try {
            $guard->onPreSave(new EntityEvent($entity, $original));
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_GROUP_CONSTRAINT, $e->reason);
        }
    }

    #[Test]
    public function a_null_checker_leaves_a_constraint_less_update_unaffected(): void
    {
        $workflow = $this->workflowWithGroupConstraint();
        $guard = $this->guard($workflow, $this->account(['use editorial transition submit_for_review']));
        $entity = $this->entity(['id' => 1, 'workflow_state' => 'review'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('review', $entity->get('workflow_state'));
    }

    #[Test]
    public function a_pointer_revision_with_an_unknown_workflow_state_falls_back_to_its_stored_unpublished_status(): void
    {
        // Same fallback, unpublished flavor: the pointer revision names a
        // state the bound workflow does not define ('legacy_state'), stored
        // status 0 — preserved as-is even though the save enters
        // 'published' (published flag true).
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'legacy_state', 'status' => 0], isNew: false);
        $guard = $this->guard($this->editorialWorkflow(), $this->account(['use editorial transition publish']), $publishedRevision);

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'review'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame(0, $entity->get('status'));
    }

    // ------------------------------------------------------------------
    // CW-v1 option-1 (#1920 PR-2): discipline flag, working-copy basis,
    // same-state republish arming.
    // ------------------------------------------------------------------

    #[Test]
    public function the_discipline_flag_is_set_true_when_a_published_pointer_exists(): void
    {
        $workflow = $this->editorialWorkflow();
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $marker = new RepublishMarker();
        $guard = $this->fullGuard($workflow, null, $publishedRevision, null, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertTrue($entity->isDefaultRevisionDisciplined());
    }

    #[Test]
    public function the_discipline_flag_is_set_false_unconditionally_when_no_published_pointer_exists_even_overriding_a_stale_true(): void
    {
        // The flag is set UNCONDITIONALLY on every guarded save — never
        // set-on-true-only — so a stale `true` from a prior disciplined
        // save of a long-lived entity object cannot leak into a later,
        // unpointered save (design §1, verifier finding 6).
        $workflow = $this->editorialWorkflow();
        $marker = new RepublishMarker();
        $guard = $this->fullGuard($workflow, null, null, null, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $entity->setDefaultRevisionDiscipline(true);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertFalse($entity->isDefaultRevisionDisciplined());
    }

    #[Test]
    public function unbound_entities_leave_the_discipline_flag_untouched(): void
    {
        // "unbound -> false" is really "untouched": the guard returns
        // immediately for an unbound entity type/bundle (class docblock),
        // before it ever reaches the discipline-flag write.
        $entityTypeManager = $this->entityTypeManager(null);
        $guard = new WorkflowStateGuard($this->bindings(null, $entityTypeManager), $entityTypeManager);

        $entity = $this->disciplinedEntity(['id' => 1], isNew: true);
        $entity->setDefaultRevisionDiscipline(true);

        $guard->onPreSave(new EntityEvent($entity));

        $this->assertTrue($entity->isDefaultRevisionDisciplined());
    }

    #[Test]
    public function guard_update_uses_the_working_copy_not_the_base_row_as_the_original_state_basis(): void
    {
        // Without the option-1 basis switch, this save would be validated
        // as published -> draft (the base row/original-entity state) and
        // would require the 'revise' transition's permission. With the
        // switch, the basis is the WORKING COPY (already 'draft' — a
        // forward draft in progress), so this is a same-state,
        // non-default-revision save: ungated (the forward-draft case
        // itself). The acting account holds NO permissions — a red proof
        // that the pre-fix code path (base-row basis) would deny this.
        $workflow = $this->editorialWorkflow();
        $workingCopy = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $marker = new RepublishMarker();
        $guard = $this->fullGuard($workflow, $this->account([]), null, $workingCopy, $marker);

        $entity = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('draft', $entity->get('workflow_state'));
    }

    #[Test]
    public function an_authorized_disciplined_revision_creating_same_state_save_into_a_default_revision_state_arms_the_republish_marker(): void
    {
        $workflow = $this->editorialWorkflow();
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $workingCopy = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $marker = new RepublishMarker();
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->fullGuard($workflow, $account, $publishedRevision, $workingCopy, $marker);

        // disciplinedEntity()'s bundle default is revisionDefault: true
        // (this test file's entityTypeManager() fixture) — no explicit
        // isNewRevision() override, so this save IS revision-creating.
        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertTrue($marker->consume($entity), 'An authorized same-state edit of served (default-revision) content must arm the republish marker.');
    }

    #[Test]
    public function an_unauthorized_disciplined_revision_creating_same_state_save_into_a_default_revision_state_is_denied_and_not_armed(): void
    {
        $workflow = $this->editorialWorkflow();
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $workingCopy = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $marker = new RepublishMarker();
        $guard = $this->fullGuard($workflow, $this->account([]), $publishedRevision, $workingCopy, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        try {
            $guard->onPreSave(new EntityEvent($entity, $original));
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }

        $this->assertFalse($marker->consume($entity), 'A denied same-state republish must not arm the marker.');
    }

    #[Test]
    public function a_same_state_save_with_a_null_account_context_arms_the_republish_marker(): void
    {
        // Null account context: edge-legality-only everywhere else in this
        // class — the same-state republish gate follows the same rule.
        $workflow = $this->editorialWorkflow();
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $workingCopy = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $marker = new RepublishMarker();
        $guard = $this->fullGuard($workflow, null, $publishedRevision, $workingCopy, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertTrue($marker->consume($entity));
    }

    #[Test]
    public function a_state_changing_save_never_arms_the_republish_marker(): void
    {
        // Arming only ever happens from the same-state branch — the
        // promote branch's first (state-changing, revision-creating) save
        // must never arm: the POST_SAVE listener would otherwise
        // double-promote inside the SAME repository->save() call
        // (design §3.1 finding A1).
        $workflow = $this->editorialWorkflow();
        $marker = new RepublishMarker();
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->fullGuard($workflow, $account, null, null, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertFalse($marker->consume($entity));
    }

    #[Test]
    public function an_unauthorized_non_revision_creating_same_state_save_into_a_default_revision_state_is_denied(): void
    {
        // Fix-wave correction (#1920 PR-2 adversarial review): the any-of
        // authorization applies to EVERY disciplined same-state save whose
        // state is default_revision: true — revision-creating or not. An
        // opt-out bundle's in-place edit of the already-published tip
        // reaches the base row directly (the writeBase rule in
        // EntityRepository::doSave()), i.e. it changes SERVED content —
        // gating only revision-creating saves let an account with plain
        // entity update access edit live published content with no
        // workflow permission checked at all.
        $workflow = $this->editorialWorkflow();
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $workingCopy = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $marker = new RepublishMarker();
        $guard = $this->fullGuard($workflow, $this->account([]), $publishedRevision, $workingCopy, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $entity->setNewRevision(false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        try {
            $guard->onPreSave(new EntityEvent($entity, $original));
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }

        $this->assertFalse($marker->consume($entity), 'A denied in-place same-state save must not arm.');
    }

    #[Test]
    public function an_authorized_non_revision_creating_same_state_save_passes_and_never_arms(): void
    {
        // The authorized counterpart: passes the (now unconditional) any-of
        // gate but never ARMS — the in-place save reaches the base row
        // directly via the storage writeBase rule; there is no orphan tip
        // to promote, so a POST_SAVE promotion would be redundant.
        $workflow = $this->editorialWorkflow();
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $workingCopy = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $marker = new RepublishMarker();
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->fullGuard($workflow, $account, $publishedRevision, $workingCopy, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $entity->setNewRevision(false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertFalse($marker->consume($entity), 'An in-place (non-revision-creating) same-state save must never arm.');
    }

    #[Test]
    public function the_archive_flows_in_place_status_flip_save_passes_the_gate_with_the_archive_permission(): void
    {
        // Fix-wave pin (#1920 PR-2 adversarial review): TransitionService's
        // second (post-pointer-move, status-flip) save is an in-place,
        // same-state save of a default_revision: true state — with the gate
        // now unconditional, it too is authorization-checked. It must PASS,
        // because the ambient account holds the fired transition's own
        // permission, which is by definition a transition INTO the target
        // state (any-of satisfied). The archive flow is the sharpest case:
        // any-of into 'archived' = the 'archive' permission.
        $workflow = new Workflow(['id' => 'editorial', 'label' => 'Editorial', 'initial_state' => 'draft',
            'states' => [
                'draft' => ['label' => 'Draft'],
                'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
                'archived' => ['label' => 'Archived', 'published' => false, 'default_revision' => true],
            ],
            'transitions' => [
                'publish' => ['label' => 'Publish', 'from' => ['draft'], 'to' => 'published'],
                'archive' => ['label' => 'Archive', 'from' => ['published'], 'to' => 'archived'],
            ],
        ]);
        // Post-pointer-move shape: the pointer (and working copy) already
        // sit on the just-promoted 'archived' revision; the flip save is
        // in-place (setNewRevision(false)) and same-state on the
        // working-copy basis.
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'archived', 'status' => 1], isNew: false);
        $workingCopy = $this->entity(['id' => 1, 'workflow_state' => 'archived'], isNew: false);
        $marker = new RepublishMarker();
        $account = $this->account(['use editorial transition archive']);
        $guard = $this->fullGuard($workflow, $account, $publishedRevision, $workingCopy, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'archived', 'status' => 0], isNew: false);
        $entity->setNewRevision(false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'archived'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertSame('archived', $entity->get('workflow_state'));
        $this->assertFalse($marker->consume($entity), 'The flip save is in-place — it must pass the gate but never arm.');
    }

    #[Test]
    public function on_pre_save_clears_a_stale_arm_from_a_previous_aborted_save_of_the_same_object(): void
    {
        // Fix-wave stale-arm fix (#1920 PR-2 adversarial review, MINOR): a
        // PRE_SAVE-aborted save (a later listener threw AFTER the guard
        // armed) must not leave an arm that a later save of the same object
        // consumes. onPreSave() unconditionally clears the marker at the
        // start of every guarded save, mirroring the unconditional
        // discipline-flag reset.
        $workflow = $this->editorialWorkflow();
        $marker = new RepublishMarker();
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->fullGuard($workflow, $account, null, null, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $marker->arm($entity); // simulated stale arm from an aborted earlier save
        $original = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);

        // A state-preserving draft save (no published pointer — nothing
        // arms, nothing is gated) must still have CLEARED the stale arm.
        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertFalse($marker->consume($entity), 'onPreSave() must clear any stale arm before anything else.');
    }

    #[Test]
    public function a_same_state_save_into_a_non_default_revision_state_never_arms(): void
    {
        // Same-state saves into a draft-like (default_revision: false)
        // state ARE the forward-draft case itself — no republish gate
        // applies.
        $workflow = $this->editorialWorkflow();
        $publishedRevision = $this->entity(['id' => 1, 'workflow_state' => 'published', 'status' => 1], isNew: false);
        $workingCopy = $this->entity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $marker = new RepublishMarker();
        $guard = $this->fullGuard($workflow, null, $publishedRevision, $workingCopy, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'draft'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertFalse($marker->consume($entity));
    }

    #[Test]
    public function a_same_state_save_with_no_published_pointer_never_arms(): void
    {
        // Never-published (or undisciplined) content: nothing to protect,
        // nothing to arm.
        $workflow = $this->editorialWorkflow();
        $marker = new RepublishMarker();
        $account = $this->account(['use editorial transition publish']);
        $guard = $this->fullGuard($workflow, $account, null, null, $marker);

        $entity = $this->disciplinedEntity(['id' => 1, 'workflow_state' => 'published'], isNew: false);
        $original = $this->entity(['id' => 1, 'workflow_state' => 'published'], isNew: false);

        $guard->onPreSave(new EntityEvent($entity, $original));

        $this->assertFalse($marker->consume($entity));
    }
}

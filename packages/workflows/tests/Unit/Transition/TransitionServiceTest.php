<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Transition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Event\WorkflowEvents;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;

/**
 * @covers \Waaseyaa\Workflows\Transition\TransitionService
 */
#[CoversClass(TransitionService::class)]
final class TransitionServiceTest extends TestCase
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

        return new WorkflowBindingResolver($configFactory, $this->entityTypeManager());
    }

    private ?Workflow $workflowForBinding = null;

    /** @var list<int> tracks how many times save() was called on the repository spy */
    private array $saveCalls = [];

    private function entityTypeManager(): EntityTypeManagerInterface
    {
        $workflow = $this->workflowForBinding;
        $saveCallsRef = &$this->saveCalls;

        return new class ($workflow, $saveCallsRef) implements EntityTypeManagerInterface {
            public function __construct(
                private readonly ?Workflow $workflow,
                private array &$saveCalls,
            ) {}

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                return new EntityType(
                    id: 'fixture',
                    label: 'Fixture',
                    class: \stdClass::class,
                    keys: ['id' => 'id', 'revision' => 'vid'],
                    revisionable: true,
                );
            }

            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function getDefinitions(): array { return []; }
            public function hasDefinition(string $entityTypeId): bool { return true; }

            public function getStorage(string $entityTypeId): EntityStorageInterface { throw new \LogicException('not needed: production getStorage() has no storageFactory (C-22 WP4)'); }

            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                if ($entityTypeId === 'workflow') {
                    return new WorkflowLookupRepository($this->workflow);
                }

                return new SpyEntityRepository($this->saveCalls);
            }
        };
    }

    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return $this->accountId !== 0; }
        };
    }

    private function entity(string $state = 'draft'): EntityInterface
    {
        return new class ($state) implements EntityInterface {
            private array $values;

            public function __construct(string $state)
            {
                $this->values = ['id' => 1, 'workflow_state' => $state, 'status' => 0];
            }

            public function id(): int|string|null { return $this->values['id']; }
            public function uuid(): string { return 'fixture-uuid'; }
            public function label(): string { return 'Fixture'; }
            public function getEntityTypeId(): string { return 'fixture'; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return false; }
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

    private function spyDispatcher(): SpyDispatcher
    {
        return new SpyDispatcher();
    }

    private function spyAuditWriter(): SpyAuditWriter
    {
        return new SpyAuditWriter();
    }

    private function service(Workflow $workflow, ?EventDispatcherInterface $dispatcher = null, ?AuditWriterInterface $auditWriter = null): TransitionService
    {
        $this->workflowForBinding = $workflow;
        $this->saveCalls = [];

        return new TransitionService(
            bindings: $this->bindings($workflow),
            entityTypeManager: $this->entityTypeManager(),
            dispatcher: $dispatcher,
            auditWriter: $auditWriter,
        );
    }

    #[Test]
    public function a_permitted_transition_applies_state_and_status_and_saves(): void
    {
        $workflow = $this->editorialWorkflow();
        $dispatcher = $this->spyDispatcher();
        $auditWriter = $this->spyAuditWriter();
        $service = $this->service($workflow, $dispatcher, $auditWriter);
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->entity('draft');

        $result = $service->transition($entity, 'publish', $account);

        $this->assertSame('draft', $result->fromState);
        $this->assertSame('published', $result->toState);
        $this->assertSame('publish', $result->transitionId);
        $this->assertSame('published', $entity->get('workflow_state'));
        $this->assertSame(1, $entity->get('status'));
        $this->assertSame(['pre', 'post'], $dispatcher->firedNames());
        $this->assertCount(1, $auditWriter->recorded);
        $this->assertSame('allowed', $auditWriter->recorded[0]->outcome);
    }

    #[Test]
    public function unbound_entity_denies_with_reason_unbound(): void
    {
        // No workflow registered for this type/bundle => unbound.
        $this->workflowForBinding = null;
        $this->saveCalls = [];
        $service = new TransitionService($this->bindings(null), $this->entityTypeManager());
        $account = $this->account(7, []);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'publish', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_UNBOUND, $e->reason);
        }
    }

    #[Test]
    public function unknown_transition_denies_with_reason_unknown_transition(): void
    {
        $service = $this->service($this->editorialWorkflow());
        $account = $this->account(7, []);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'nonexistent', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_UNKNOWN_TRANSITION, $e->reason);
        }
    }

    #[Test]
    public function wrong_from_state_denies_with_reason_illegal_edge(): void
    {
        $service = $this->service($this->editorialWorkflow());
        $account = $this->account(7, ['use editorial transition submit_for_review']);
        // 'submit_for_review' only fires from 'draft'; entity is in 'published'.
        $entity = $this->entity('published');

        try {
            $service->transition($entity, 'submit_for_review', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_ILLEGAL_EDGE, $e->reason);
        }
    }

    #[Test]
    public function missing_permission_denies_with_reason_permission(): void
    {
        $auditWriter = $this->spyAuditWriter();
        $service = $this->service($this->editorialWorkflow(), null, $auditWriter);
        $account = $this->account(7, []);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'publish', $account);
            $this->fail('Expected TransitionDeniedException');
        } catch (TransitionDeniedException $e) {
            $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $e->reason);
        }

        $this->assertCount(1, $auditWriter->recorded);
        $this->assertSame('denied', $auditWriter->recorded[0]->outcome);
    }

    #[Test]
    public function a_denied_transition_never_saves_or_dispatches_post(): void
    {
        $dispatcher = $this->spyDispatcher();
        $service = $this->service($this->editorialWorkflow(), $dispatcher);
        $account = $this->account(7, []);
        $entity = $this->entity('draft');

        try {
            $service->transition($entity, 'publish', $account);
        } catch (TransitionDeniedException) {
            // expected
        }

        $this->assertSame([], $dispatcher->firedNames());
        $this->assertSame('draft', $entity->get('workflow_state'));
    }

    #[Test]
    public function get_available_transitions_filters_by_from_state_and_permission(): void
    {
        $service = $this->service($this->editorialWorkflow());
        $account = $this->account(7, ['use editorial transition submit_for_review']);
        $entity = $this->entity('draft');

        $available = $service->getAvailableTransitions($entity, $account);

        $this->assertCount(1, $available);
        $this->assertSame('submit_for_review', $available[0]->id);
    }

    #[Test]
    public function get_available_transitions_is_empty_for_an_unbound_entity(): void
    {
        $service = new TransitionService($this->bindings(null), $this->entityTypeManager());
        $account = $this->account(7, ['use editorial transition publish']);
        $entity = $this->entity('draft');

        $this->assertSame([], $service->getAvailableTransitions($entity, $account));
    }
}

/**
 * Stub for the 'workflow' entity type's repository — production
 * WorkflowBindingResolver::resolve() calls getRepository('workflow')->find(),
 * never getStorage() (see WorkflowBindingResolver's deviation note).
 */
final class WorkflowLookupRepository implements EntityRepositoryInterface
{
    public function __construct(private readonly ?Workflow $workflow) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { return $this->workflow; }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }
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
}

final class SpyDispatcher implements EventDispatcherInterface
{
    /** @var list<string> */
    private array $fired = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->fired[] = $eventName === WorkflowEvents::PRE_TRANSITION->value ? 'pre' : 'post';

        return $event;
    }

    /** @return list<string> */
    public function firedNames(): array
    {
        return $this->fired;
    }
}

final class SpyAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->recorded[] = $descriptor;
    }
}

final class SpyEntityRepository implements EntityRepositoryInterface
{
    public int $saveCount = 0;

    public function __construct(private array &$saveCalls) {}

    public function create(array $values = []): EntityInterface { throw new \LogicException('not needed'); }
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface { throw new \LogicException('not needed'); }
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array { return []; }
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array { return []; }
    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('not needed'); }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $this->saveCalls[] = 1;
        $this->saveCount++;

        return 2;
    }

    public function delete(EntityInterface $entity): void {}
    public function exists(string $id): bool { return true; }
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
}

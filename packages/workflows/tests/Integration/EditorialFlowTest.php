<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Listener\WorkflowStateGuard;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;

/**
 * Integration spine (CW-v1 WP-1, docs/specs/content-workflow.md, "Testing
 * requirements — Integration spine"): one end-to-end editorial story driven
 * through the real save path (create, guarded) and the real
 * {@see TransitionService} (every subsequent transition), over real SQLite —
 * create (forced initial state, unpublished) -> submit for review -> publish
 * DENIED for an account without the permission -> publish (allowed) ->
 * archive. Asserts an audit entry exists for every fired transition,
 * allowed and denied alike.
 *
 * Revision promotion (default_revision mechanics) is WP-2 — this spine
 * exercises `workflow_state` + `status` only, per the WP-1 scope boundary.
 */
#[CoversNothing]
final class EditorialFlowTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'editorial_flow_subject';

    #[Test]
    public function create_submit_deny_publish_archive_with_full_audit_trail(): void
    {
        [$entityTypeManager, $service, $auditWriter] = $this->wireEngine();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        // --- Create: the save-path guard forces initial_state + unpublished. ---
        // 'bundle' is set explicitly (mirrors Node's 'type' field): the base
        // schema always has a bundle column, so an entity reloaded via
        // find() after save() carries a real (even if unset) empty-string
        // value there — EntityBase::bundle() then returns that stored value
        // rather than falling back to the entity type id. Real bound content
        // types (e.g. node) always set their bundle explicitly; this fixture
        // does the same.
        $entity = new EditorialFlowSubject(['bundle' => self::ENTITY_TYPE_ID], self::ENTITY_TYPE_ID, $this->entityKeys());
        $repository->save($entity);

        $stored = $repository->find((string) $entity->id());
        $this->assertNotNull($stored);
        $this->assertSame('draft', $stored->get('workflow_state'));
        $this->assertSame(0, $stored->get('status'));

        $reviewer = $this->account(1, ['use editorial transition submit_for_review']);
        $publisher = $this->account(2, ['use editorial transition publish', 'use editorial transition archive']);

        // --- submit_for_review: allowed for the reviewer. ---
        $result = $service->transition($stored, 'submit_for_review', $reviewer);
        $this->assertSame('draft', $result->fromState);
        $this->assertSame('review', $result->toState);

        $stored = $repository->find((string) $entity->id());
        $this->assertSame('review', $stored->get('workflow_state'));

        // --- publish: DENIED for the reviewer (lacks the publish permission). ---
        $denied = null;
        try {
            $service->transition($stored, 'publish', $reviewer);
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        // Denial must not mutate state.
        $stored = $repository->find((string) $entity->id());
        $this->assertSame('review', $stored->get('workflow_state'));

        // --- publish: allowed for the publisher. ---
        $result = $service->transition($stored, 'publish', $publisher);
        $this->assertSame('published', $result->toState);

        $stored = $repository->find((string) $entity->id());
        $this->assertSame('published', $stored->get('workflow_state'));
        $this->assertSame(1, $stored->get('status'));

        // --- archive: allowed for the publisher; unpublishes. ---
        $result = $service->transition($stored, 'archive', $publisher);
        $this->assertSame('archived', $result->toState);

        $stored = $repository->find((string) $entity->id());
        $this->assertSame('archived', $stored->get('workflow_state'));
        $this->assertSame(0, $stored->get('status'));

        // --- Audit trail: one entry per fired transition, allowed AND denied. ---
        $this->assertCount(4, $auditWriter->recorded, 'Expected one audit entry per TransitionService call (3 allowed + 1 denied).');
        $outcomes = \array_map(static fn(AuditEventDescriptor $d): string => $d->outcome, $auditWriter->recorded);
        $this->assertSame(['allowed', 'denied', 'allowed', 'allowed'], $outcomes);

        foreach ($auditWriter->recorded as $descriptor) {
            $this->assertSame('editorial', $descriptor->attributes['workflow']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function entityKeys(): array
    {
        return ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];
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
     * @return array{0: EntityTypeManager, 1: TransitionService, 2: EditorialFlowSpyAuditWriter}
     */
    private function wireEngine(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            self::ENTITY_TYPE_ID . '.' . self::ENTITY_TYPE_ID => 'editorial',
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
                new SqlStorageDriver($resolver),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workflow',
            label: 'Workflow',
            class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'workflows',
        ));

        $entityTypeManager->registerEntityType(new EntityType(
            id: self::ENTITY_TYPE_ID,
            label: 'Editorial flow subject',
            class: EditorialFlowSubject::class,
            keys: $this->entityKeys(),
            revisionable: true,
        ));

        $editorial = new Workflow(DefaultWorkflows::EDITORIAL);
        $editorial->enforceIsNew();
        $entityTypeManager->getRepository('workflow')->save($editorial);

        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);

        // Save-path guard: proves create-forces-initial-state without going
        // through TransitionService (GuardWiringTest already proves the
        // container-wiring seam; this spine exercises the guard's runtime
        // behavior alongside the service in one coherent story).
        $guard = new WorkflowStateGuard($bindings);
        $dispatcher->addListener(EntityEvents::PRE_SAVE->value, [$guard, 'onPreSave']);

        $auditWriter = new EditorialFlowSpyAuditWriter();

        $service = new TransitionService(
            bindings: $bindings,
            entityTypeManager: $entityTypeManager,
            dispatcher: $dispatcher,
            auditWriter: $auditWriter,
        );

        return [$entityTypeManager, $service, $auditWriter];
    }
}

final class EditorialFlowSpyAuditWriter implements AuditWriterInterface
{
    /** @var list<AuditEventDescriptor> */
    public array $recorded = [];

    public function record(AuditEventDescriptor $descriptor): void
    {
        $this->recorded[] = $descriptor;
    }
}

final class EditorialFlowSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
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

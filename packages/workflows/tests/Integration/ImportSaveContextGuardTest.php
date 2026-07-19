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
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard;
use Waaseyaa\Workflows\Listener\WorkflowRepublishListener;
use Waaseyaa\Workflows\Listener\WorkflowStateGuard;
use Waaseyaa\Workflows\Republish\RepublishMarker;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;

/**
 * WP-2 plan self-review item (c) (#1920): verifies — rather than assumes —
 * that {@see \Waaseyaa\EntityStorage\SaveContext::isImport} saves do not hit
 * {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard}'s WP-1 create rule
 * incorrectly.
 *
 * Ground truth established by reading the code, pinned here as an executable
 * trace: {@see \Waaseyaa\Entity\Event\EntityEvent} (the event
 * `WorkflowStateGuard::onPreSave()` subscribes to) carries only
 * `entity`/`originalEntity`/`langcode` — it does NOT carry the `SaveContext`
 * at all. `SaveContext` only reaches the SEPARATE, newer
 * `BeforeSaveEvent`/`AfterSaveEvent` pair (dispatched by the same
 * `EntityRepository::save()` call, immediately after `EntityEvents::PRE_SAVE`
 * — see `EntityRepository::save()`), which `WorkflowStateGuard` does not
 * subscribe to. Consequence: `isImport` has ZERO effect on the guard's
 * decisions — an import-flagged save is validated EXACTLY like any other
 * create/update, no special-case, no bypass, no guard change needed (matches
 * the plan's own conclusion). This is verified, not merely asserted, by the
 * two scenarios below:
 *
 * 1. The common migration shape (no explicit `workflow_state` on create) is
 *    unaffected: forced into `initial_state`, no permission required,
 *    `isImport` or not.
 * 2. The narrower shape (create names a non-initial `workflow_state`
 *    directly — e.g. preserving an already-published legacy record's status)
 *    is DENIED with `REASON_PERMISSION` when there is no ambient acting
 *    account — identically whether or not `isImport` is set. This is the
 *    WP-0/WP-1 "born-published hole" close (`guardCreate()`'s docblock)
 *    applying uniformly to migration imports: a null ambient account can
 *    never create non-initial-state content, `isImport` notwithstanding.
 * 3. The sanctioned way to import already-published content is documented
 *    here, not silently discovered by a migration author later: either (a)
 *    thread an authenticated {@see \Waaseyaa\Access\Context\AccountContextInterface}
 *    account holding the transition's permission through the import run, or
 *    (b) import as `initial_state` (the common shape) and promote it
 *    afterward via {@see \Waaseyaa\Workflows\Transition\TransitionService::transition()},
 *    which takes its `AccountInterface` as an explicit argument rather than
 *    relying on ambient context.
 *
 * **CW-v1 option-1 update-path adjacency (#1920 PR-2, design §3.1):** the
 * contract above is create-path only. Once a row carries a LIVE published
 * pointer, an import-flagged UPDATE that changes served content (same
 * `workflow_state`, disciplined, revision-creating) is the exact same-state
 * republish shape {@see WorkflowStateGuard::guardSameStateRepublish()}
 * gates — `isImport` still has zero special-case effect on the guard (the
 * headline finding above), but the underlying rule itself is NEW as of
 * PR-2: an authenticated ambient account without any-of authorization is
 * now denied (previously: no gate existed at all — this content-workflow
 * discipline literally did not exist pre-option-1); a null ambient account
 * (the common bulk-import shape) still passes AND, per the arm/consume
 * two-step, promotes the freshly-imported content through the
 * `setPublishedRevision()` choke point — outcome-equivalent to the direct
 * base-row write a pre-option-1 import produced.
 */
#[CoversNothing]
final class ImportSaveContextGuardTest extends TestCase
{
    private const string ENTITY_TYPE_ID = 'import_guard_subject';

    #[Test]
    public function an_import_create_with_no_explicit_state_is_forced_into_initial_state_same_as_any_other_create(): void
    {
        [$entityTypeManager] = $this->bootWiredGuard();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        $entity = new ImportGuardSubject(['bundle' => self::ENTITY_TYPE_ID], self::ENTITY_TYPE_ID, $this->entityKeys());

        // No ambient account context at all (typical CLI/migration-worker
        // shape) — must still succeed: the create rule's "requestedState is
        // null" branch never checks a permission.
        $repository->save($entity, true, SaveContext::default()->asImport());

        $stored = $repository->find((string) $entity->id());
        $this->assertNotNull($stored);
        $this->assertSame('draft', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($stored));
        $this->assertSame(0, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($stored));
    }

    #[Test]
    public function an_import_create_naming_a_non_initial_state_is_denied_without_an_ambient_account_exactly_like_a_non_import_create(): void
    {
        [$entityTypeManager] = $this->bootWiredGuard();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        // Mirrors a migration trying to preserve an already-published
        // legacy record's status directly on create.
        $importEntity = new ImportGuardSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'published'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );

        $importDenied = null;
        try {
            $repository->save($importEntity, true, SaveContext::default()->asImport());
        } catch (TransitionDeniedException $e) {
            $importDenied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $importDenied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $importDenied->reason);

        // Proof this is not import-specific: the IDENTICAL shape without
        // isImport denies the same way, for the same reason.
        $ordinaryEntity = new ImportGuardSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'published'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $ordinaryDenied = null;
        try {
            $repository->save($ordinaryEntity);
        } catch (TransitionDeniedException $e) {
            $ordinaryDenied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $ordinaryDenied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $ordinaryDenied->reason);
    }

    #[Test]
    public function the_sanctioned_import_path_for_already_published_content_is_an_authenticated_ambient_account(): void
    {
        [$entityTypeManager, $accountContext] = $this->bootWiredGuard();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        $importer = $this->account(9, ['use editorial transition publish']);
        $accountContext->set($importer);

        $entity = new ImportGuardSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'published'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );

        // Succeeds once an authenticated account holding the transition
        // permission is threaded through — isImport is still along for the
        // ride and still has no bearing on the outcome.
        $repository->save($entity, true, SaveContext::default()->asImport());

        $stored = $repository->find((string) $entity->id());
        $this->assertNotNull($stored);
        $this->assertSame('published', \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::state($stored));
        $this->assertSame(1, \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectView::status($stored));
    }

    #[Test]
    public function an_import_update_of_an_already_published_bound_row_requires_any_of_authorization_with_an_ambient_account_and_passes_and_promotes_without_one(): void
    {
        [$entityTypeManager, $accountContext] = $this->bootWiredGuard();
        $repository = $entityTypeManager->getRepository(self::ENTITY_TYPE_ID);

        // Establish a live published pointer directly (simulates a
        // backfill/prior import run — this test does not exercise
        // TransitionService, so the pointer is set via the storage
        // primitive itself, exactly as `revision-system-unified.md`'s
        // backfill procedure does).
        $importer = $this->account(9, ['use editorial transition publish']);
        $accountContext->set($importer);
        $published = new ImportGuardSubject(
            ['bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'published', 'title' => 'Original import'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $repository->save($published, true, SaveContext::default()->asImport());
        $entityId = (string) $published->id();
        $repository->setPublishedRevision($entityId, (int) $published->get('revision_id'));
        $this->assertNotNull($repository->loadPublishedRevision($entityId), 'A live published pointer must exist for this adjacency to be meaningful.');

        // An authenticated ambient account with NO any-of authorization
        // into 'published': a same-state, revision-creating import UPDATE
        // is newly denied (CW-v1 option-1) — before PR-2 this content-
        // workflow discipline did not exist at all.
        $unauthorizedImporter = $this->account(10, []);
        $accountContext->set($unauthorizedImporter);
        $deniedUpdate = new ImportGuardSubject(
            ['id' => $published->id(), 'bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'published', 'title' => 'Unauthorized re-import'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $deniedUpdate->setNewRevision(true);

        $denied = null;
        try {
            $repository->save($deniedUpdate, true, SaveContext::default()->asImport());
        } catch (TransitionDeniedException $e) {
            $denied = $e;
        }
        $this->assertInstanceOf(TransitionDeniedException::class, $denied);
        $this->assertSame(TransitionDeniedException::REASON_PERMISSION, $denied->reason);

        $afterDenial = $repository->find($entityId);
        $this->assertNotNull($afterDenial);
        $this->assertSame('Original import', $afterDenial->get('title'), 'A denied import update must not commit.');

        // A null ambient account (the common bulk-import shape): the same
        // same-state update passes AND promotes through the choke point —
        // outcome-equivalent to a pre-option-1 import's direct base-row
        // write.
        $accountContext->set(null);
        $nullContextUpdate = new ImportGuardSubject(
            ['id' => $published->id(), 'bundle' => self::ENTITY_TYPE_ID, 'workflow_state' => 'published', 'title' => 'Null-context re-import'],
            self::ENTITY_TYPE_ID,
            $this->entityKeys(),
        );
        $nullContextUpdate->setNewRevision(true);
        $repository->save($nullContextUpdate, true, SaveContext::default()->asImport());

        $afterNullContextImport = $repository->find($entityId);
        $this->assertNotNull($afterNullContextImport);
        $this->assertSame('Null-context re-import', $afterNullContextImport->get('title'), 'A null-context import update must pass AND promote through setPublishedRevision().');
        $this->assertSame(
            (string) $repository->loadPublishedRevision($entityId)?->get('revision_id'),
            (string) $afterNullContextImport->get('revision_id'),
            'The base row and the published pointer must land on the same revision after the auto-promotion.',
        );
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
     * @return array{0: EntityTypeManager, 1: RequestAccountContext}
     */
    private function bootWiredGuard(): array
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

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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
            label: 'Import guard subject',
            class: ImportGuardSubject::class,
            keys: $this->entityKeys(),
            revisionable: true,
        ));

        $editorial = new Workflow(DefaultWorkflows::EDITORIAL);
        $editorial->enforceIsNew();
        $entityTypeManager->getRepository('workflow')->save($editorial);

        $bindings = new WorkflowBindingResolver($configFactory, $entityTypeManager);
        $accountContext = new RequestAccountContext();

        // CW-v1 option-1 (#1920 PR-2): the shared arm/consume marker, wired
        // the same way WorkflowServiceProvider wires it in production —
        // needed for the update-path adjacency test below, which exercises
        // the same-state republish two-step end to end.
        $republishMarker = new RepublishMarker();
        $guard = new WorkflowStateGuard($bindings, $entityTypeManager, $accountContext, republishMarker: $republishMarker);
        $dispatcher->addListener(EntityEvents::PRE_SAVE->value, [$guard, 'onPreSave']);

        // CW-v1 option-1 (#1920 PR-2): the pointer-move guard is what sets
        // the discipline flag on BeforeRevisionPointerMoveEvent — without
        // it, WorkflowRepublishListener's setPublishedRevision() call below
        // takes the UNFLAGGED single-column-pointer-only path and the base
        // row's OTHER columns (title, revision_id) never get copied
        // forward, even though the pointer value itself moves. Wiring all
        // three collaborators together mirrors WorkflowServiceProvider::boot().
        $pointerGuard = new WorkflowPointerMoveGuard($bindings, $entityTypeManager, $accountContext);
        $dispatcher->addListener(BeforeRevisionPointerMoveEvent::class, [$pointerGuard, 'onBeforePointerMove']);

        $republishListener = new WorkflowRepublishListener($republishMarker, $entityTypeManager);
        $dispatcher->addListener(EntityEvents::POST_SAVE->value, [$republishListener, 'onPostSave']);

        return [$entityTypeManager, $accountContext];
    }
}

final class ImportGuardSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
{
    use RevisionableEntityTrait;
    use \Waaseyaa\Workflows\Tests\Support\WorkflowSubjectFields;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

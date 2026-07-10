<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;

/**
 * WP-2 rework, review finding #8: `WorkflowStateGuard` throws
 * {@see TransitionDeniedException} from PRE_SAVE inside
 * `EntityRepository::save()`. Before this mapping existed, that denial
 * propagated straight through `JsonApiController` uncaught — a routine
 * editorial permission/edge mistake surfaced as an HTTP 500. This suite
 * pins the mapping: REASON_PERMISSION -> 403, every other reason -> 422,
 * across all three `->save(` sites (create(), the plain PATCH path, and
 * the expectation-stated PATCH path), never an uncaught exception.
 *
 * The guard is simulated with a plain PRE_SAVE listener on the real
 * dispatcher (mirroring how {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard}
 * is wired in production) rather than the real workflows engine — this
 * test only needs to prove the controller's catch/mapping, not the guard's
 * transition logic.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerWorkflowDeniedTest extends TestCase
{
    private const array REV_KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];

    private DBALDatabase $db;
    private EntityTypeManager $entityTypeManager;
    private JsonApiController $controller;
    private EntityRepository $repo;

    /** Set by a test to arm the fake PRE_SAVE guard listener; null = no denial. */
    private ?string $denyReason = null;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->db);

        // Stands in for Waaseyaa\Workflows\Listener\WorkflowStateGuard::onPreSave():
        // throws TransitionDeniedException from PRE_SAVE exactly like the real
        // guard does, armed per-test via $this->denyReason.
        $dispatcher->addListener(EntityEvents::PRE_SAVE->value, function (EntityEvent $event): void {
            if ($this->denyReason !== null) {
                throw new TransitionDeniedException($this->denyReason, "Transition denied: {$this->denyReason}.");
            }
        });

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver): EntityRepository {
                return new EntityRepository(
                    $definition,
                    new SqlStorageDriver($resolver),
                    $dispatcher,
                    $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                    $this->db,
                    validator: new EntityValidator(Validation::createValidator()),
                );
            },
        );

        $revisionableType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: self::REV_KEYS,
            revisionable: true,
            revisionDefault: true,
        );
        $this->entityTypeManager->registerEntityType($revisionableType);
        $handler = new SqlSchemaHandler($revisionableType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
        );

        $repo = $this->entityTypeManager->getRepository('test_revisionable');
        \assert($repo instanceof EntityRepository);
        $this->repo = $repo;
    }

    /** Seed the fixture entity at revision 1 with the guard disarmed. */
    private function seedEntity(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'wf-1']);
        $entity->enforceIsNew();
        $this->repo->save($entity);
    }

    // -----------------------------------------------------------------------
    // create()
    // -----------------------------------------------------------------------

    #[Test]
    public function createReturns403WhenGuardDeniesForPermission(): void
    {
        $this->denyReason = TransitionDeniedException::REASON_PERMISSION;

        $doc = $this->controller->store('test_revisionable', [
            'data' => ['type' => 'test_revisionable', 'attributes' => ['title' => 'x', 'id' => '2', 'uuid' => 'wf-2']],
        ]);
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertCount(1, $array['errors']);
        $this->assertSame(
            [
                'status' => '403',
                'title' => 'Forbidden',
                'code' => 'WORKFLOW_TRANSITION_DENIED',
                'detail' => 'Transition denied: permission.',
                'meta' => ['reason' => 'permission'],
            ],
            $array['errors'][0],
        );
        $this->assertNull($this->repo->find('2'), 'a denied create persists nothing');
    }

    #[Test]
    public function createReturns422WhenGuardDeniesForIllegalEdge(): void
    {
        $this->denyReason = TransitionDeniedException::REASON_ILLEGAL_EDGE;

        $doc = $this->controller->store('test_revisionable', [
            'data' => ['type' => 'test_revisionable', 'attributes' => ['title' => 'x', 'id' => '2', 'uuid' => 'wf-2']],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertCount(1, $array['errors']);
        $this->assertSame(
            [
                'status' => '422',
                'title' => 'Unprocessable Entity',
                'code' => 'WORKFLOW_TRANSITION_DENIED',
                'detail' => 'Transition denied: illegal_edge.',
                'meta' => ['reason' => 'illegal_edge'],
            ],
            $array['errors'][0],
        );
        $this->assertNull($this->repo->find('2'), 'a denied create persists nothing');
    }

    // -----------------------------------------------------------------------
    // update() — plain PATCH path (no expected_revision_id)
    // -----------------------------------------------------------------------

    #[Test]
    public function plainUpdateReturns403WhenGuardDeniesForPermission(): void
    {
        $this->seedEntity();
        $this->denyReason = TransitionDeniedException::REASON_PERMISSION;

        $doc = $this->controller->update('test_revisionable', '1', [
            'data' => ['type' => 'test_revisionable', 'attributes' => ['title' => 'v2']],
        ]);
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame(
            [
                'status' => '403',
                'title' => 'Forbidden',
                'code' => 'WORKFLOW_TRANSITION_DENIED',
                'detail' => 'Transition denied: permission.',
                'meta' => ['reason' => 'permission'],
            ],
            $array['errors'][0],
        );
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v1', $reloaded->label(), 'a denied plain PATCH persists nothing');
    }

    #[Test]
    public function plainUpdateReturns422WhenGuardDeniesForIllegalEdge(): void
    {
        $this->seedEntity();
        $this->denyReason = TransitionDeniedException::REASON_ILLEGAL_EDGE;

        $doc = $this->controller->update('test_revisionable', '1', [
            'data' => ['type' => 'test_revisionable', 'attributes' => ['title' => 'v2']],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame(
            [
                'status' => '422',
                'title' => 'Unprocessable Entity',
                'code' => 'WORKFLOW_TRANSITION_DENIED',
                'detail' => 'Transition denied: illegal_edge.',
                'meta' => ['reason' => 'illegal_edge'],
            ],
            $array['errors'][0],
        );
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v1', $reloaded->label(), 'a denied plain PATCH persists nothing');
    }

    // -----------------------------------------------------------------------
    // update() — expectation-stated PATCH path (saveWithExpectation())
    // -----------------------------------------------------------------------

    #[Test]
    public function expectationUpdateReturns403WhenGuardDeniesForPermission(): void
    {
        $this->seedEntity();
        $this->denyReason = TransitionDeniedException::REASON_PERMISSION;

        $doc = $this->controller->update('test_revisionable', '1', [
            'data' => [
                'type' => 'test_revisionable',
                'attributes' => ['title' => 'v2'],
                'meta' => ['expected_revision_id' => 1],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame(
            [
                'status' => '403',
                'title' => 'Forbidden',
                'code' => 'WORKFLOW_TRANSITION_DENIED',
                'detail' => 'Transition denied: permission.',
                'meta' => ['reason' => 'permission'],
            ],
            $array['errors'][0],
        );
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v1', $reloaded->label(), 'a denied expectation PATCH persists nothing');
    }

    #[Test]
    public function expectationUpdateReturns422WhenGuardDeniesForIllegalEdge(): void
    {
        $this->seedEntity();
        $this->denyReason = TransitionDeniedException::REASON_ILLEGAL_EDGE;

        $doc = $this->controller->update('test_revisionable', '1', [
            'data' => [
                'type' => 'test_revisionable',
                'attributes' => ['title' => 'v2'],
                'meta' => ['expected_revision_id' => 1],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame(
            [
                'status' => '422',
                'title' => 'Unprocessable Entity',
                'code' => 'WORKFLOW_TRANSITION_DENIED',
                'detail' => 'Transition denied: illegal_edge.',
                'meta' => ['reason' => 'illegal_edge'],
            ],
            $array['errors'][0],
        );
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v1', $reloaded->label(), 'a denied expectation PATCH persists nothing');
    }
}

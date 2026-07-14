<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Validation;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Mission optimistic-locking-01KTXCHY WP02/T011 — the JSON:API conditional
 * PATCH per contracts/conflict-surfaces.md §9–16: the data.meta seam, the
 * request-state table (absent / invalid / non-revisionable / stale 409 /
 * current / validation 422 / uuid locator), the 409 body member-by-member,
 * and the show() revision_id attribute pin (FR-008).
 *
 * C-22 WP3 superseded contract §14 (2026-07-01): the no-expectation PATCH
 * path no longer routes through the legacy `getStorage()->save()` — it now
 * shares the same revision-aware `getRepository()->save()` pipeline as an
 * expectation-stated PATCH, just without the conflict-detection guard.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerConflictTest extends TestCase
{
    private const string UUID = '0f7e3a52-1111-2222-3333-444455556666';
    private const array REV_KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'];
    private const array PLAIN_KEYS = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'];

    private DBALDatabase $db;
    private EntityTypeManager $entityTypeManager;
    private JsonApiController $controller;
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->db);

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            // C-22 WP4: the legacy SqlEntityStorage engine is removed; the kernel
            // now wires getStorage() to null (EntityTypeManagerFactory) — it is a
            // "bring your own EntityStorageInterface" extension seam only.
            null,
            // The kernel's getRepository() shape: the revision-aware pipeline.
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

        $plainType = new EntityType(
            id: 'test_plain',
            label: 'Plain',
            class: TestStorageEntity::class,
            keys: self::PLAIN_KEYS,
        );
        $this->entityTypeManager->registerEntityType($plainType);
        new SqlSchemaHandler($plainType, $this->db)->ensureTable();

        $constrainedType = new EntityType(
            id: 'test_constrained',
            label: 'Constrained',
            class: TestRevisionableEntity::class,
            keys: self::REV_KEYS,
            revisionable: true,
            revisionDefault: true,
            constraints: ['title' => [new NotBlank(), new Length(min: 3)]],
        );
        $this->entityTypeManager->registerEntityType($constrainedType);
        $constrainedHandler = new SqlSchemaHandler($constrainedType, $this->db);
        $constrainedHandler->ensureTable();
        $constrainedHandler->ensureRevisionTable();

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
        );

        $repo = $this->entityTypeManager->getRepository('test_revisionable');
        \assert($repo instanceof EntityRepository);
        $this->repo = $repo;
    }

    /** Seed the revisionable fixture entity at revision 1. */
    private function seedEntity(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => self::UUID]);
        $entity->enforceIsNew();
        $this->repo->save($entity);
    }

    /** Move the head to revision 2 with a competing direct save. */
    private function moveHead(): void
    {
        $winner = $this->repo->find('1');
        \assert($winner instanceof TestRevisionableEntity);
        $winner->set('title', 'v2-winner');
        $this->repo->save($winner);
    }

    /** @param array<string, mixed> $attributes */
    private function patchBody(array $attributes, ?int $expected = null, mixed $rawExpected = null): array
    {
        $body = ['data' => ['type' => 'test_revisionable', 'attributes' => $attributes]];
        if ($expected !== null) {
            $body['data']['meta'] = ['expected_revision_id' => $expected];
        } elseif ($rawExpected !== null) {
            $body['data']['meta'] = ['expected_revision_id' => $rawExpected];
        }

        return $body;
    }

    private function revisionRowCount(): int
    {
        foreach ($this->db->query('SELECT COUNT(*) AS c FROM test_revisionable_revision') as $row) {
            return (int) $row['c'];
        }

        return -1;
    }

    // -----------------------------------------------------------------------
    // No-expectation invariance (contract §14, superseded 2026-07-01 by C-22 WP3)
    // -----------------------------------------------------------------------

    #[Test]
    public function patchWithoutExpectationStillUsesRevisionAwareRepository(): void
    {
        $this->seedEntity();
        $revisionsBefore = $this->revisionRowCount();

        $doc = $this->controller->update('test_revisionable', '1', $this->patchBody(['title' => 'v2']));
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertSame('v2', $array['data']['attributes']['title']);
        // C-22 WP3: the no-expectation PATCH now persists through the same
        // getRepository()->save() pipeline as the expectation-stated path —
        // there is no separate legacy storage path — so a revision IS cut.
        $this->assertGreaterThan($revisionsBefore, $this->revisionRowCount(), 'no-expectation PATCH now goes through the revision-aware repository');
    }

    #[Test]
    public function patchWithoutMetaAtAllIsTreatedAsNoExpectation(): void
    {
        $this->seedEntity();
        $this->moveHead();

        // Stale world, no expectation stated: legacy last-write-wins applies.
        $doc = $this->controller->update('test_revisionable', '1', $this->patchBody(['title' => 'v3']));

        $this->assertSame(200, $doc->statusCode);
        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v3', $reloaded->label());
    }

    // -----------------------------------------------------------------------
    // Validation of the meta member (contract §10)
    // -----------------------------------------------------------------------

    #[Test]
    public function invalidExpectationReturns400(): void
    {
        $this->seedEntity();

        foreach ([0, -1, '5', 1.5, true] as $invalid) {
            $doc = $this->controller->update('test_revisionable', '1', $this->patchBody(['title' => 'x'], rawExpected: $invalid));
            $array = $doc->toArray();

            $this->assertSame(400, $doc->statusCode, var_export($invalid, true) . ' must be a 400');
            $this->assertSame('400', $array['errors'][0]['status']);
            $this->assertSame(
                'data.meta.expected_revision_id must be a positive integer.',
                $array['errors'][0]['detail'],
            );
        }

        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v1', $reloaded->label(), 'no invalid request saved anything');
    }

    #[Test]
    public function expectationOnANonRevisionableTypeReturns422(): void
    {
        $plainRepo = $this->entityTypeManager->getRepository('test_plain');
        $seed = new TestStorageEntity(values: ['id' => '1', 'label' => 'x'], entityTypeId: 'test_plain', entityKeys: self::PLAIN_KEYS);
        $seed->enforceIsNew();
        $plainRepo->save($seed);

        $doc = $this->controller->update('test_plain', '1', [
            'data' => [
                'type' => 'test_plain',
                'attributes' => ['label' => 'y'],
                'meta' => ['expected_revision_id' => 1],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame('422', $array['errors'][0]['status']);
        $this->assertSame(
            "Entity type 'test_plain' does not support revision expectations.",
            $array['errors'][0]['detail'],
        );
        $reloaded = $plainRepo->find('1');
        $this->assertNotNull($reloaded);
        $this->assertSame('x', $reloaded->get('label'), 'a stated expectation is never silently dropped');
    }

    // -----------------------------------------------------------------------
    // Stale expectation → 409 (contract §12)
    // -----------------------------------------------------------------------

    #[Test]
    public function staleExpectationReturns409WithTheFullErrorShape(): void
    {
        $this->seedEntity();
        $this->moveHead();
        $revisionsBefore = $this->revisionRowCount();

        $doc = $this->controller->update('test_revisionable', '1', $this->patchBody(['title' => 'loser'], expected: 1));
        $array = $doc->toArray();

        $this->assertSame(409, $doc->statusCode);
        $this->assertCount(1, $array['errors']);
        $this->assertSame(
            [
                'status' => '409',
                'title' => 'Conflict',
                'code' => 'REVISION_CONFLICT',
                'detail' => "Entity of type 'test_revisionable' with ID '1' was modified: expected revision 1, current revision is 2.",
                'meta' => ['expected_revision_id' => 1, 'current_revision_id' => 2],
            ],
            $array['errors'][0],
        );

        $reloaded = $this->repo->find('1');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('v2-winner', $reloaded->label(), 'the competing write is intact, the stale write absent');
        $this->assertSame($revisionsBefore, $this->revisionRowCount(), 'a refused save cuts nothing');
    }

    #[Test]
    public function uuidRoutedConflictNamesTheRealEntityId(): void
    {
        $this->seedEntity();
        $this->moveHead();

        $doc = $this->controller->update('test_revisionable', self::UUID, $this->patchBody(['title' => 'loser'], expected: 1));
        $array = $doc->toArray();

        $this->assertSame(409, $doc->statusCode);
        $this->assertSame('REVISION_CONFLICT', $array['errors'][0]['code']);
        // Locator honesty (contract §15): the real id, not the uuid locator.
        $this->assertSame(
            "Entity of type 'test_revisionable' with ID '1' was modified: expected revision 1, current revision is 2.",
            $array['errors'][0]['detail'],
        );
        $this->assertSame(['expected_revision_id' => 1, 'current_revision_id' => 2], $array['errors'][0]['meta']);
    }

    // -----------------------------------------------------------------------
    // Matching expectation → repository pipeline (contract §11)
    // -----------------------------------------------------------------------

    #[Test]
    public function matchingExpectationAppliesCutsARevisionAndReturnsTheNewHead(): void
    {
        $this->seedEntity();
        $revisionsBefore = $this->revisionRowCount();

        $doc = $this->controller->update('test_revisionable', '1', $this->patchBody(['title' => 'v2'], expected: 1));
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertSame('v2', $array['data']['attributes']['title']);
        // The attributes carry the new head so the client can re-state.
        $this->assertSame(2, $array['data']['attributes']['revision_id']);
        // The repository pipeline cut a revision — intended and documented.
        $this->assertSame($revisionsBefore + 1, $this->revisionRowCount());
    }

    #[Test]
    public function repositoryValidationFailureMapsTo422(): void
    {
        $repo = $this->entityTypeManager->getRepository('test_constrained');
        \assert($repo instanceof EntityRepository);
        $entity = new TestRevisionableEntity(values: ['title' => 'valid', 'id' => '1', 'uuid' => 'c1']);
        $entity->enforceIsNew();
        $repo->save($entity);

        $doc = $this->controller->update('test_constrained', '1', [
            'data' => [
                'type' => 'test_constrained',
                'attributes' => ['title' => ''],
                'meta' => ['expected_revision_id' => 1],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame('422', $array['errors'][0]['status']);
        $this->assertStringContainsString(
            "Validation failed for entity of type 'test_constrained'",
            $array['errors'][0]['detail'],
        );
    }

    #[Test]
    public function createValidationFailureMapsTo422(): void
    {
        $doc = $this->controller->store('test_constrained', [
            'data' => [
                'type' => 'test_constrained',
                'attributes' => ['title' => 'x'],
            ],
        ]);
        $array = $doc->toArray();

        self::assertSame(422, $doc->statusCode);
        self::assertSame('422', $array['errors'][0]['status']);
        self::assertStringContainsString("Validation failed for entity of type 'test_constrained'", $array['errors'][0]['detail']);
    }

    #[Test]
    public function patchWithoutExpectationValidationFailureMapsTo422(): void
    {
        $repo = $this->entityTypeManager->getRepository('test_constrained');
        $entity = new TestRevisionableEntity(values: ['title' => 'valid', 'id' => '1', 'uuid' => 'c2']);
        $entity->enforceIsNew();
        $repo->save($entity);

        $doc = $this->controller->update('test_constrained', '1', [
            'data' => [
                'type' => 'test_constrained',
                'attributes' => ['title' => ''],
            ],
        ]);
        $array = $doc->toArray();

        self::assertSame(422, $doc->statusCode);
        self::assertSame('422', $array['errors'][0]['status']);
        self::assertStringContainsString("Validation failed for entity of type 'test_constrained'", $array['errors'][0]['detail']);
    }

    // -----------------------------------------------------------------------
    // Unique-constraint trips map to 409, not raw 500 (audit-remediation
    // batch 2026-07-02, WP2 review MAJOR). create() has caught
    // UniqueConstraintViolationException → 409 since its introduction;
    // update()'s two save paths surfaced the raw DBAL exception (500,
    // possible SQL leak under APP_DEBUG). Both PATCH paths must mirror
    // create()'s Conflict mapping. Real constraint trips (a UNIQUE index on
    // the fixture table + a colliding UPDATE), not hand-built exceptions.
    // -----------------------------------------------------------------------

    #[Test]
    public function patchWithoutExpectationMapsUniqueConstraintViolationTo409(): void
    {
        $this->db->query('CREATE UNIQUE INDEX test_plain_label_unique ON test_plain (label)');
        $plainRepo = $this->entityTypeManager->getRepository('test_plain');
        foreach ([['1', 'alpha'], ['2', 'beta']] as [$id, $label]) {
            $seed = new TestStorageEntity(values: ['id' => $id, 'label' => $label], entityTypeId: 'test_plain', entityKeys: self::PLAIN_KEYS);
            $seed->enforceIsNew();
            $plainRepo->save($seed);
        }

        // PATCH entity 2's label to entity 1's value — trips the unique index.
        $doc = $this->controller->update('test_plain', '2', [
            'data' => ['type' => 'test_plain', 'attributes' => ['label' => 'alpha']],
        ]);
        $array = $doc->toArray();

        $this->assertSame(409, $doc->statusCode);
        $this->assertSame('409', $array['errors'][0]['status']);
        $this->assertSame('Conflict', $array['errors'][0]['title']);
        $this->assertSame(
            "Updating entity of type 'test_plain' with ID '2' violated a uniqueness constraint.",
            $array['errors'][0]['detail'],
        );

        $reloaded = $plainRepo->find('2');
        $this->assertNotNull($reloaded);
        $this->assertSame('beta', $reloaded->get('label'), 'the refused update persisted nothing');
    }

    #[Test]
    public function patchWithExpectationMapsUniqueConstraintViolationTo409(): void
    {
        $this->db->query('CREATE UNIQUE INDEX test_revisionable_title_unique ON test_revisionable (title)');
        $this->seedEntity();
        $sibling = new TestRevisionableEntity(values: ['title' => 'other', 'id' => '2', 'uuid' => 'u2']);
        $sibling->enforceIsNew();
        $this->repo->save($sibling);

        // PATCH entity 2's title to entity 1's value with a matching
        // expectation — the expectation passes, then the base-table UPDATE
        // trips the unique index inside the save pipeline.
        $doc = $this->controller->update('test_revisionable', '2', $this->patchBody(['title' => 'v1'], expected: 1));
        $array = $doc->toArray();

        $this->assertSame(409, $doc->statusCode);
        $this->assertSame('409', $array['errors'][0]['status']);
        $this->assertSame('Conflict', $array['errors'][0]['title']);
        $this->assertSame(
            "Updating entity of type 'test_revisionable' with ID '2' violated a uniqueness constraint.",
            $array['errors'][0]['detail'],
        );

        $reloaded = $this->repo->find('2');
        \assert($reloaded instanceof TestRevisionableEntity);
        $this->assertSame('other', $reloaded->label(), 'the refused update persisted nothing');
    }

    // -----------------------------------------------------------------------
    // FR-008 pin (contract §16)
    // -----------------------------------------------------------------------

    #[Test]
    public function showEmitsRevisionIdAsAnAttribute(): void
    {
        $this->seedEntity();

        $doc = $this->controller->show('test_revisionable', '1');
        $array = $doc->toArray();

        // PINNED, load-bearing (FR-008): expectation-forming clients read this
        // attribute; removing or renaming it is a consumer break.
        $this->assertSame(1, $array['data']['attributes']['revision_id']);
    }
}

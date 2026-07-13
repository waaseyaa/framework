<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;

/**
 * CW-v1 option-1 design §5 (PR-4), root-cause findings #1/#2
 * (`.superpowers/sdd/final-review-findings.md`): the JSON:API write-side
 * field allowlist. `store()`/`update()` used to apply every submitted
 * attribute with only per-field ACCESS as the gate — no allowlist restricted
 * writes to declared, non-bookkeeping fields, so `published_revision_id`/
 * `revision_id` (real base columns with no field definition and no policy)
 * were writable by any account with plain entity `update` access. This suite
 * pins the structural allowlist ({@see \Waaseyaa\Entity\Write\EntityWritePayloadGuard})
 * at the JSON:API layer; the pinned end-to-end reproduction of finding #1
 * itself (real Node + workflow wiring, raw-SQL pointer assertion) lives in
 * `packages/api/tests/Integration/WriteAllowlistPointerBypassFlowTest.php`.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerWriteAllowlistTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: [
                'body' => new FieldDefinition(name: 'body', type: 'text'),
            ],
        ));

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            new ResourceSerializer($this->entityTypeManager),
        );
    }

    private function createAndSaveEntity(array $values): TestEntity
    {
        $entity = $this->storage->create($values);
        $this->storage->save($entity);

        return $entity;
    }

    // --- Undeclared attribute (test #2 of the brief) ---

    #[Test]
    public function storeRejectsAnUndeclaredAttribute(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New', 'not_a_field' => 1],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame('FIELD_NOT_WRITABLE', $array['errors'][0]['code']);
        $this->assertStringContainsString('not_a_field', $array['errors'][0]['detail']);
        $this->assertSame(['not_a_field'], $array['errors'][0]['meta']['refused_keys']);
        $this->assertSame([], $this->storage->loadMultiple(), 'a refused create must persist nothing');
    }

    #[Test]
    public function updateRejectsAnUndeclaredAttribute(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Original']);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'attributes' => ['not_a_field' => 1],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame('FIELD_NOT_WRITABLE', $array['errors'][0]['code']);
        $this->assertStringContainsString('not_a_field', $array['errors'][0]['detail']);

        $reloaded = $this->storage->load($entity->id());
        $this->assertSame('Original', $reloaded->get('title'), 'a refused update must apply nothing');
    }

    // --- revision_id / published_revision_id on create AND update ---

    #[Test]
    public function storeRejectsRevisionIdAttribute(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New', 'revision_id' => 99],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame(['revision_id'], $array['errors'][0]['meta']['refused_keys']);
        $this->assertSame([], $this->storage->loadMultiple());
    }

    #[Test]
    public function storeRejectsPublishedRevisionIdAttribute(): void
    {
        // Findings #1/#2's exact root-cause column: no field definition, no
        // shipped field-access policy, no entity-key kind on any type.
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New', 'published_revision_id' => 99],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame(['published_revision_id'], $array['errors'][0]['meta']['refused_keys']);
        $this->assertSame([], $this->storage->loadMultiple());
    }

    #[Test]
    public function updateRejectsRevisionIdAttribute(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Original']);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'attributes' => ['revision_id' => 99],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame(['revision_id'], $array['errors'][0]['meta']['refused_keys']);
    }

    #[Test]
    public function updateRejectsPublishedRevisionIdAttribute(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Original']);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'attributes' => ['published_revision_id' => 99],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame(['published_revision_id'], $array['errors'][0]['meta']['refused_keys']);
    }

    #[Test]
    public function multipleRefusedKeysAreAllNamedSortedAlphabetically(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New', 'published_revision_id' => 99, 'revision_id' => 1, 'not_a_field' => 1],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame(
            ['not_a_field', 'published_revision_id', 'revision_id'],
            $array['errors'][0]['meta']['refused_keys'],
        );
    }

    // --- Declared fields (title/body) still writable ---

    #[Test]
    public function storeAcceptsDeclaredFields(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New Article', 'body' => 'Body content.'],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(201, $doc->statusCode);
        $this->assertSame('New Article', $array['data']['attributes']['title']);
        $this->assertSame('Body content.', $array['data']['attributes']['body']);
    }

    #[Test]
    public function updateAcceptsDeclaredFields(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Original', 'body' => 'Original body.']);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'Updated', 'body' => 'Updated body.'],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertSame('Updated', $array['data']['attributes']['title']);
        $this->assertSame('Updated body.', $array['data']['attributes']['body']);
    }

    // --- Echo-tolerant rejection (PR-4 rework) ---

    #[Test]
    public function updateAcceptsAnEchoedPointerColumnAndStripsItBeforeApply(): void
    {
        // TestEntity has no revision key registered, so revision_id is
        // refused only via EntityWritePayloadGuard's LITERAL_FLOOR, never
        // populated by storage — its "current stored value" is absent
        // (treated as null). Echoing null back must pass.
        $entity = $this->createAndSaveEntity(['title' => 'Original']);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'Updated', 'revision_id' => null],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode, 'a null-echoed absent bookkeeping column must not 422: ' . json_encode($array));
        $this->assertSame('Updated', $array['data']['attributes']['title']);
    }

    #[Test]
    public function updateRejectsAnUndeclaredFieldEvenWhenItsValueMatchesTheStoredValue(): void
    {
        // Echo tolerance applies ONLY to the identity/bookkeeping set — an
        // undeclared field ('not_a_field' has no FieldDefinition on this
        // fixture) is hard-refused unconditionally, even when it happens to
        // equal a stored value under the same key. Undeclared-field storage
        // is not exercised here (InMemoryEntityStorage has no such key), so
        // this pins purely the structural branch, matching the guard-level
        // unit test of the same name.
        $entity = $this->createAndSaveEntity(['title' => 'Original']);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'attributes' => ['not_a_field' => null],
            ],
        ]);
        $array = $doc->toArray();

        $this->assertSame(422, $doc->statusCode);
        $this->assertSame(['not_a_field'], $array['errors'][0]['meta']['refused_keys']);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Query;

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
 * Audit R2 WP1: an anonymous JSON:API collection request could pass an arbitrary
 * query-string key as a filter/sort field name. That name flowed unvalidated
 * through QueryParser -> QueryApplier -> SqlEntityQuery::resolveField(), which
 * interpolates it RAW into a `json_extract('$.<field>')` SQL fragment — only the
 * *value* is bound as a parameter, the field *identifier* is not. A field name
 * containing a single quote breaks out of that string literal (SQL injection).
 *
 * The fix replaces the old deny-list ({@see JsonApiController::ALWAYS_INTERNAL_FIELDS}
 * only) with an allowlist: a filter/sort field must be either a declared field
 * ({@see \Waaseyaa\Entity\EntityTypeManagerInterface::resolveFieldDefinitions()})
 * or a structural entity key ({@see \Waaseyaa\Entity\EntityTypeInterface::getKeys()}).
 * Everything else — including a syntactically malicious field name — is rejected
 * with 400 before it ever reaches the query layer.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerFieldAllowlistTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: [
                'status' => new FieldDefinition(name: 'status', type: 'integer', targetEntityTypeId: 'article'),
            ],
        ));

        $this->controller = new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager));
    }

    #[Test]
    public function maliciousFilterFieldNameIsRejected(): void
    {
        // A single-quote-bearing field name is exactly what breaks out of the
        // 'json_extract(_data, \'$.<field>\')' string literal at the storage sink.
        $doc = $this->controller->index('article', [
            'filter' => ["title') UNION SELECT 1--" => 'x'],
        ]);

        $this->assertBadRequest($doc->toArray());
    }

    #[Test]
    public function maliciousSortFieldNameIsRejected(): void
    {
        $doc = $this->controller->index('article', [
            'sort' => "id') UNION SELECT 1--",
        ]);

        $this->assertBadRequest($doc->toArray());
    }

    #[Test]
    public function plainUndeclaredFilterFieldIsRejected(): void
    {
        // Not malicious, just never declared — the allowlist rejects it regardless of payload
        // shape. This is the behavioural core of the fix: undeclared fields no longer pass
        // through to the query layer at all, whether or not they happen to be harmless.
        $doc = $this->controller->index('article', [
            'filter' => ['not_a_real_field' => 'x'],
        ]);

        $this->assertBadRequest($doc->toArray());
    }

    #[Test]
    public function plainUndeclaredSortFieldIsRejected(): void
    {
        $doc = $this->controller->index('article', [
            'sort' => 'not_a_real_field',
        ]);

        $this->assertBadRequest($doc->toArray());
    }

    #[Test]
    public function declaredFieldFilterStillWorks(): void
    {
        $this->createAndSaveEntity(['title' => 'Visible', 'status' => 1]);
        $this->createAndSaveEntity(['title' => 'Hidden', 'status' => 0]);

        $array = $this->controller->index('article', ['filter' => ['status' => 1]])->toArray();

        $this->assertArrayNotHasKey('errors', $array);
        $this->assertCount(1, $array['data']);
        $this->assertSame('Visible', $array['data'][0]['attributes']['title']);
    }

    #[Test]
    public function entityKeyFieldSortStillWorks(): void
    {
        $this->createAndSaveEntity(['title' => 'Zulu']);
        $this->createAndSaveEntity(['title' => 'Alpha']);

        // 'title' is the entity type's declared label key, not a #[Field] — it must pass the
        // allowlist via getKeys(), not resolveFieldDefinitions().
        $array = $this->controller->index('article', ['sort' => 'title'])->toArray();

        $this->assertArrayNotHasKey('errors', $array);
        $this->assertSame('Alpha', $array['data'][0]['attributes']['title']);
        $this->assertSame('Zulu', $array['data'][1]['attributes']['title']);
    }

    /** @param array<string, mixed> $array */
    private function assertBadRequest(array $array): void
    {
        $this->assertArrayHasKey('errors', $array, 'expected a 400 error document');
        $this->assertSame('400', $array['errors'][0]['status']);
    }

    /** @param array<string, mixed> $values */
    private function createAndSaveEntity(array $values): TestEntity
    {
        /** @var TestEntity $entity */
        $entity = $this->storage->create($values);
        $this->storage->save($entity);

        return $entity;
    }
}

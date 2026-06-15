<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;

/**
 * B-3: a collection query must not filter or sort on internal/credential fields. Otherwise an
 * anonymous GET collection can filter on a secret field (`pass`, `two_factor_secret`, a reset
 * token, …) and use match/no-match as a value-enumeration oracle even though the field is
 * never serialised.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerInternalFieldQueryTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $entityTypeManager = new EntityTypeManager(new EventDispatcher(), fn() => $this->storage);
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            // A class-declared field marked internal — must be unqueryable (the two_factor_secret case).
            _fieldDefinitions: [
                'secret_field' => new FieldDefinition(name: 'secret_field', type: 'string', settings: ['internal' => true], targetEntityTypeId: 'article'),
            ],
        ));

        $this->controller = new JsonApiController($entityTypeManager, new ResourceSerializer($entityTypeManager));
    }

    #[Test]
    public function filterOnCredentialFieldIsRejected(): void
    {
        // `pass` is an always-internal credential key even though it is an undeclared _data key.
        $this->assertBadRequest($this->controller->index('article', ['filter' => ['pass' => 'secret']])->toArray());
    }

    #[Test]
    public function filterOnPasswordHashIsRejected(): void
    {
        $this->assertBadRequest($this->controller->index('article', ['filter' => ['password_hash' => 'x']])->toArray());
    }

    #[Test]
    public function sortOnCredentialFieldIsRejected(): void
    {
        $this->assertBadRequest($this->controller->index('article', ['sort' => 'pass'])->toArray());
    }

    #[Test]
    public function filterOnDeclaredInternalFieldIsRejected(): void
    {
        $this->assertBadRequest($this->controller->index('article', ['filter' => ['secret_field' => 'x']])->toArray());
    }

    #[Test]
    public function legitimateFilterOnPublicFieldStillWorks(): void
    {
        $this->createAndSaveEntity(['title' => 'Visible', 'status' => 1]);

        $array = $this->controller->index('article', ['filter' => ['status' => 1]])->toArray();

        // Filtering on an ordinary (undeclared, non-internal) field is unaffected.
        $this->assertArrayNotHasKey('errors', $array);
        $this->assertArrayHasKey('data', $array);
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

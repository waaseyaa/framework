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
 * #2552: `?representation=` is a JsonApiController gate. The integration
 * flow pins the HTTP contract without attributing coverage to this class;
 * this unit file is what attributes those branches to JsonApiController
 * for the changed-line coverage ratchet.
 */
#[CoversClass(JsonApiController::class)]
final class JsonApiControllerEditorRepresentationTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');
        $manager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: [
                'body' => new FieldDefinition(name: 'body', type: 'text_long'),
            ],
        ));
        $this->controller = new JsonApiController($manager, new ResourceSerializer($manager));
    }

    #[Test]
    public function indexRefusesTheEditingRepresentation(): void
    {
        $doc = $this->controller->index('article', ['representation' => 'editing']);

        self::assertSame(400, $doc->statusCode);
        self::assertNull($doc->data);
        self::assertStringContainsString('collection', (string) $doc->errors[0]->detail);
    }

    #[Test]
    public function indexAcceptsTheExplicitRenderedRepresentation(): void
    {
        $doc = $this->controller->index('article', ['representation' => 'rendered']);

        self::assertSame([], $doc->errors);
        self::assertIsArray($doc->data);
    }

    #[Test]
    public function showRefusesAnUnknownRepresentationBeforeTheEntityIsLoaded(): void
    {
        $doc = $this->controller->show('article', 'missing-id', [
            'workingCopy' => '1',
            'representation' => 'raw',
        ]);

        self::assertSame(400, $doc->statusCode);
        self::assertNull($doc->data);
        self::assertStringContainsString('Unsupported', (string) $doc->errors[0]->detail);
        self::assertStringNotContainsString('not found', strtolower((string) $doc->errors[0]->detail));
    }

    #[Test]
    public function showRefusesANonStringRepresentation(): void
    {
        $doc = $this->controller->show('article', 'missing-id', ['representation' => ['editing']]);

        self::assertSame(400, $doc->statusCode);
        self::assertNull($doc->data);
    }

    #[Test]
    public function showRefusesEditingWithoutWorkingCopy(): void
    {
        $entity = $this->seed();

        $doc = $this->controller->show('article', $entity->id(), ['representation' => 'editing']);

        self::assertSame(400, $doc->statusCode);
        self::assertNull($doc->data);
        self::assertStringContainsString('workingCopy', (string) $doc->errors[0]->detail);
    }

    #[Test]
    public function showAcceptsTheExplicitRenderedRepresentation(): void
    {
        $entity = $this->seed();

        $doc = $this->controller->show('article', $entity->id(), ['representation' => 'rendered']);

        self::assertSame([], $doc->errors);
        self::assertSame(['representation' => 'rendered'], $doc->meta);
    }

    #[Test]
    public function editingWithWorkingCopyOnAnUnwiredControllerFailsClosed(): void
    {
        $entity = $this->seed();

        $doc = $this->controller->show('article', $entity->id(), [
            'workingCopy' => '1',
            'representation' => 'editing',
        ]);

        self::assertSame(403, $doc->statusCode);
        self::assertNull($doc->data);
        $wire = json_encode($doc->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('sfn-program-contact', $wire);
    }

    private function seed(): TestEntity
    {
        $entity = new TestEntity([
            'title' => 'Program contacts',
            'type' => 'article',
            'body' => '<div class="sfn-program-contact">x</div>',
        ]);
        $entity->enforceIsNew();
        $this->storage->save($entity);

        return $entity;
    }
}

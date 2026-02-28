<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Controller;

use Aurora\Api\Controller\TranslationController;
use Aurora\Api\ResourceSerializer;
use Aurora\Api\Tests\Fixtures\InMemoryEntityStorage;
use Aurora\Api\Tests\Fixtures\TestEntity;
use Aurora\Api\Tests\Fixtures\TranslatableTestEntity;
use Aurora\Entity\EntityType;
use Aurora\Entity\EntityTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(TranslationController::class)]
final class TranslationControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private ResourceSerializer $serializer;
    private TranslationController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TranslatableTestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
                'langcode' => 'langcode',
            ],
            translatable: true,
        ));

        // Register a non-translatable entity type for error testing.
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'config',
            label: 'Config',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            translatable: false,
        ));

        $this->serializer = new ResourceSerializer($this->entityTypeManager);
        $this->controller = new TranslationController(
            $this->entityTypeManager,
            $this->serializer,
        );
    }

    // --- Index (list translations) ---

    #[Test]
    public function indexListsTranslationsForEntity(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        // Add a French translation.
        $fr = $entity->getTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $doc = $this->controller->index('article', $entity->id());
        $array = $doc->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertCount(2, $array['data']);
        $this->assertSame(2, $array['meta']['total']);

        // Check that we have en and fr translations.
        $langcodes = array_map(
            fn(array $resource) => $resource['meta']['langcode'],
            $array['data'],
        );
        $this->assertContains('en', $langcodes);
        $this->assertContains('fr', $langcodes);
    }

    #[Test]
    public function indexReturnsOriginalLanguageOnly(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->index('article', $entity->id());
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        $this->assertSame('en', $array['data'][0]['meta']['langcode']);
    }

    #[Test]
    public function indexReturnsErrorForUnknownEntityType(): void
    {
        $doc = $this->controller->index('nonexistent', 1);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    #[Test]
    public function indexReturnsErrorForNonTranslatableType(): void
    {
        $doc = $this->controller->index('config', 1);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('422', $array['errors'][0]['status']);
        $this->assertStringContainsString('does not support translations', $array['errors'][0]['detail']);
    }

    #[Test]
    public function indexReturnsErrorForMissingEntity(): void
    {
        $doc = $this->controller->index('article', 9999);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    // --- Show (get specific translation) ---

    #[Test]
    public function showReturnsSpecificTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $fr = $entity->getTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $doc = $this->controller->show('article', $entity->id(), 'fr');
        $array = $doc->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertSame('fr', $array['data']['meta']['langcode']);
        $this->assertSame('Bonjour', $array['data']['attributes']['title']);
    }

    #[Test]
    public function showReturnsOriginalLanguage(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->show('article', $entity->id(), 'en');
        $array = $doc->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertSame('en', $array['data']['meta']['langcode']);
        $this->assertSame('Hello', $array['data']['attributes']['title']);
    }

    #[Test]
    public function showReturnsErrorForMissingTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->show('article', $entity->id(), 'de');
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
        $this->assertStringContainsString('de', $array['errors'][0]['detail']);
    }

    // --- Store (create translation) ---

    #[Test]
    public function storeCreatesNewTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $data = [
            'data' => [
                'attributes' => [
                    'title' => 'Hola',
                ],
            ],
        ];

        $doc = $this->controller->store('article', $entity->id(), 'es', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertSame('es', $array['data']['meta']['langcode']);
        $this->assertSame('Hola', $array['data']['attributes']['title']);
        $this->assertTrue($array['meta']['created']);
        $this->assertSame(201, $doc->statusCode);
    }

    #[Test]
    public function storeReturnsConflictForExistingTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $entity->getTranslation('fr');
        $this->storage->save($entity);

        $data = [
            'data' => [
                'attributes' => ['title' => 'Bonjour'],
            ],
        ];

        $doc = $this->controller->store('article', $entity->id(), 'fr', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('409', $array['errors'][0]['status']);
    }

    // --- Update (modify translation) ---

    #[Test]
    public function updateModifiesExistingTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $fr = $entity->getTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $data = [
            'data' => [
                'attributes' => ['title' => 'Salut'],
            ],
        ];

        $doc = $this->controller->update('article', $entity->id(), 'fr', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertSame('fr', $array['data']['meta']['langcode']);
        $this->assertSame('Salut', $array['data']['attributes']['title']);
    }

    #[Test]
    public function updateReturnsErrorForMissingTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $data = [
            'data' => [
                'attributes' => ['title' => 'Hallo'],
            ],
        ];

        $doc = $this->controller->update('article', $entity->id(), 'de', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    // --- Destroy (delete translation) ---

    #[Test]
    public function destroyRemovesTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $fr = $entity->getTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $doc = $this->controller->destroy('article', $entity->id(), 'fr');
        $array = $doc->toArray();

        $this->assertNull($array['data']);
        $this->assertTrue($array['meta']['deleted']);
        $this->assertSame('fr', $array['meta']['langcode']);
        $this->assertSame(204, $doc->statusCode);
    }

    #[Test]
    public function destroyRejectsOriginalLanguageDeletion(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->destroy('article', $entity->id(), 'en');
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('422', $array['errors'][0]['status']);
        $this->assertStringContainsString('original language', $array['errors'][0]['detail']);
    }

    #[Test]
    public function destroyReturnsErrorForMissingTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->destroy('article', $entity->id(), 'de');
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    // --- Helpers ---

    private function createTranslatableEntity(array $values): TranslatableTestEntity
    {
        /** @var TranslatableTestEntity $entity */
        $entity = new TranslatableTestEntity(
            values: $values,
            entityTypeId: 'article',
        );
        $this->storage->save($entity);

        return $entity;
    }
}

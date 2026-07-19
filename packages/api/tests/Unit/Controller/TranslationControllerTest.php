<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\TranslationController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\ConfigContentTestEntity;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\ReadOnlyTranslatableTestEntity;
use Waaseyaa\Api\Tests\Fixtures\TranslatableTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(TranslationController::class)]
final class TranslationControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private InMemoryEntityStorage $readonlyStorage;
    private ResourceSerializer $serializer;
    private TranslationController $controller;
    private TranslationController $forbiddenController;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');
        $this->readonlyStorage = new InMemoryEntityStorage('readonly');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            function (\Waaseyaa\Entity\EntityTypeInterface $definition) {
                return match ($definition->id()) {
                    'readonly' => $this->readonlyStorage,
                    default    => $this->storage,
                };
            },
            // C-22 WP3: read/write path now goes through the canonical repository.
            function (string $entityTypeId) {
                return match ($entityTypeId) {
                    'readonly' => new InMemoryEntityRepository($this->readonlyStorage),
                    default    => new InMemoryEntityRepository($this->storage),
                };
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TranslatableTestEntity::class,
            keys: TranslatableTestEntity::definitionKeys(),
            translatable: true,
        ));

        // Register a non-translatable entity type for error testing.
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'config',
            label: 'Config',
            class: ConfigContentTestEntity::class,
            keys: ConfigContentTestEntity::definitionKeys(),
            translatable: false,
        ));

        // Register a read-only translatable type: implements TranslatableInterface
        // but NOT MutableTranslatableInterface. Used to verify that store() returns
        // 422 instead of calling the non-existent addTranslation().
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'readonly',
            label: 'Read-Only Translatable',
            class: ReadOnlyTranslatableTestEntity::class,
            keys: ReadOnlyTranslatableTestEntity::definitionKeys(),
            translatable: true,
        ));

        $this->serializer = new ResourceSerializer($this->entityTypeManager);

        // Controller that allows all access.
        $this->controller = new TranslationController(
            $this->entityTypeManager,
            $this->makeAccessHandler(allow: true),
            $this->serializer,
        );

        // Controller that denies all access.
        $this->forbiddenController = new TranslationController(
            $this->entityTypeManager,
            $this->makeAccessHandler(allow: false),
            $this->serializer,
        );
    }

    // --- Index (list translations) ---

    #[Test]
    public function indexListsTranslationsForEntity(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        // Add a French translation.
        $fr = $entity->addTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $doc = $this->controller->index($this->makeRequest(), 'article', $entity->id());
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

        $doc = $this->controller->index($this->makeRequest(), 'article', $entity->id());
        $array = $doc->toArray();

        $this->assertCount(1, $array['data']);
        $this->assertSame('en', $array['data'][0]['meta']['langcode']);
    }

    #[Test]
    public function indexReturnsErrorForUnknownEntityType(): void
    {
        $doc = $this->controller->index($this->makeRequest(), 'nonexistent', 1);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    #[Test]
    public function indexReturnsErrorForNonTranslatableType(): void
    {
        $doc = $this->controller->index($this->makeRequest(), 'config', 1);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('422', $array['errors'][0]['status']);
        $this->assertStringContainsString('does not support translations', $array['errors'][0]['detail']);
    }

    #[Test]
    public function indexReturnsErrorForMissingEntity(): void
    {
        $doc = $this->controller->index($this->makeRequest(), 'article', 9999);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    #[Test]
    public function indexForbiddenReturns403(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->forbiddenController->index($this->makeRequest(), 'article', $entity->id());
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame('403', $array['errors'][0]['status']);
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);
    }

    #[Test]
    public function indexNoAccountReturns403(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->index(new Request(), 'article', $entity->id());
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);
    }

    // --- Show (get specific translation) ---

    #[Test]
    public function showReturnsSpecificTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $fr = $entity->addTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $doc = $this->controller->show($this->makeRequest(), 'article', $entity->id(), 'fr');
        $array = $doc->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertSame('fr', $array['data']['meta']['langcode']);
        $this->assertSame('Bonjour', $array['data']['attributes']['title']);
    }

    #[Test]
    public function showReturnsOriginalLanguage(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->show($this->makeRequest(), 'article', $entity->id(), 'en');
        $array = $doc->toArray();

        $this->assertArrayHasKey('data', $array);
        $this->assertSame('en', $array['data']['meta']['langcode']);
        $this->assertSame('Hello', $array['data']['attributes']['title']);
    }

    #[Test]
    public function showReturnsErrorForMissingTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->show($this->makeRequest(), 'article', $entity->id(), 'de');
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
        $this->assertStringContainsString('de', $array['errors'][0]['detail']);
    }

    #[Test]
    public function showForbiddenReturns403(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->forbiddenController->show($this->makeRequest(), 'article', $entity->id(), 'en');
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);
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

        $doc = $this->controller->store($this->makeRequest(), 'article', $entity->id(), 'es', $data);
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
        $entity->addTranslation('fr');
        $this->storage->save($entity);

        $data = [
            'data' => [
                'attributes' => ['title' => 'Bonjour'],
            ],
        ];

        $doc = $this->controller->store($this->makeRequest(), 'article', $entity->id(), 'fr', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('409', $array['errors'][0]['status']);
    }

    #[Test]
    public function storeReturns422ForNonMutableTranslatableEntity(): void
    {
        // ReadOnlyTranslatableTestEntity implements TranslatableInterface but NOT
        // MutableTranslatableInterface, so store() must return 422 instead of
        // calling the non-existent addTranslation().
        $entity = new ReadOnlyTranslatableTestEntity(
            values: ['title' => 'Hello', 'langcode' => 'en'],
            entityTypeId: 'readonly',
        );
        $this->readonlyStorage->save($entity);

        $data = [
            'data' => [
                'attributes' => ['title' => 'Bonjour'],
            ],
        ];

        $doc = $this->controller->store($this->makeRequest(), 'readonly', $entity->id(), 'fr', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('422', $array['errors'][0]['status']);
        $this->assertStringContainsString('does not support creating translations', $array['errors'][0]['detail']);
    }

    #[Test]
    public function storeForbiddenReturns403(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $data = ['data' => ['attributes' => ['title' => 'Hola']]];

        $doc = $this->forbiddenController->store($this->makeRequest(), 'article', $entity->id(), 'es', $data);
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);
    }

    // --- Update (modify translation) ---

    #[Test]
    public function updateModifiesExistingTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $fr = $entity->addTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $data = [
            'data' => [
                'attributes' => ['title' => 'Salut'],
            ],
        ];

        $doc = $this->controller->update($this->makeRequest(), 'article', $entity->id(), 'fr', $data);
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

        $doc = $this->controller->update($this->makeRequest(), 'article', $entity->id(), 'de', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    #[Test]
    public function updateForbiddenReturns403(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $data = ['data' => ['attributes' => ['title' => 'Hacked']]];

        $doc = $this->forbiddenController->update($this->makeRequest(), 'article', $entity->id(), 'en', $data);
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);
    }

    // --- Destroy (delete translation) ---

    #[Test]
    public function destroyRemovesTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $fr = $entity->addTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $doc = $this->controller->destroy($this->makeRequest(), 'article', $entity->id(), 'fr');
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

        $doc = $this->controller->destroy($this->makeRequest(), 'article', $entity->id(), 'en');
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('422', $array['errors'][0]['status']);
        $this->assertStringContainsString('original language', $array['errors'][0]['detail']);
    }

    #[Test]
    public function destroyReturnsErrorForMissingTranslation(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);

        $doc = $this->controller->destroy($this->makeRequest(), 'article', $entity->id(), 'de');
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    #[Test]
    public function destroyForbiddenReturns403(): void
    {
        $entity = $this->createTranslatableEntity(['title' => 'Hello', 'langcode' => 'en']);
        $fr = $entity->addTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        $doc = $this->forbiddenController->destroy($this->makeRequest(), 'article', $entity->id(), 'fr');
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode);
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);
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

    /**
     * Create a Request with a test account set on the `_account` attribute.
     */
    private function makeRequest(): Request
    {
        $request = new Request();
        $request->attributes->set('_account', $this->makeAccount(42));

        return $request;
    }

    /**
     * Create a simple test AccountInterface implementation.
     *
     * @param string[] $roles
     */
    private function makeAccount(int $id, array $roles = ['authenticated']): AuthorizationPrincipalInterface
    {
        return new AuthorizationPrincipal($id, true, $roles, [], 'test');
    }

    /**
     * Build an EntityAccessHandler that always allows or always denies.
     *
     * EntityAccessHandler is not final and not mockable without instantiation,
     * so we feed it an anonymous AccessPolicyInterface that returns a fixed result.
     */
    private function makeAccessHandler(bool $allow): EntityAccessHandler
    {
        $handler = new EntityAccessHandler();
        $handler->addPolicy(
            new class ($allow) implements AccessPolicyInterface {
                public function __construct(private bool $allow) {}

                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return $this->allow ? AccessResult::allowed() : AccessResult::forbidden();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return $this->allow ? AccessResult::allowed() : AccessResult::forbidden();
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return true;
                }
            },
        );

        return $handler;
    }
}

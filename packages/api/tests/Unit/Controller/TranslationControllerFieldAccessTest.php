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
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Controller\TranslationController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TranslatableTestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Regression test for audit B-6: TranslationController::store() and update()
 * must apply the per-field edit gate (mirroring JsonApiController / the B-1 fix)
 * so a caller with entity-level create/update access cannot mutate a
 * FieldAccessPolicy-forbidden field via the translation endpoints.
 *
 * The policy here ALLOWS entity-level create/update (so checkAccess passes) but
 * FORBIDS field-edit on `secret`. Before the fix both write paths set every
 * submitted attribute unconditionally; the test asserts they now return 403 and
 * never persist the forbidden field, while a non-forbidden field still works.
 */
#[CoversClass(TranslationController::class)]
final class TranslationControllerFieldAccessTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private TranslationController $controller;

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
            class: TranslatableTestEntity::class,
            keys: TranslatableTestEntity::definitionKeys(),
            translatable: true,
        ));

        $handler = new EntityAccessHandler();
        $handler->addPolicy($this->fieldGatingPolicy('secret'));

        $this->controller = new TranslationController(
            $entityTypeManager,
            $handler,
            new ResourceSerializer($entityTypeManager),
        );
    }

    #[Test]
    public function updateRejectsForbiddenFieldEdit(): void
    {
        $entity = $this->createEntityWithFrTranslation();

        $data = ['data' => ['attributes' => ['secret' => 'leaked']]];
        $doc = $this->controller->update($this->makeRequest(), 'article', $entity->id(), 'fr', $data);
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode, 'update() must reject a field-edit-forbidden attribute.');
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);

        // The forbidden field must NOT have been written (the gate returns before set()).
        $reloaded = $this->storage->load($entity->id());
        self::assertInstanceOf(TranslatableTestEntity::class, $reloaded);
        $this->assertNotSame('leaked', $reloaded->getTranslation('fr')->get('secret'));
    }

    #[Test]
    public function storeRejectsForbiddenFieldEdit(): void
    {
        $entity = $this->createEntityWithFrTranslation();

        $data = ['data' => ['attributes' => ['secret' => 'leaked']]];
        $doc = $this->controller->store($this->makeRequest(), 'article', $entity->id(), 'es', $data);
        $array = $doc->toArray();

        $this->assertSame(403, $doc->statusCode, 'store() must reject a field-edit-forbidden attribute.');
        $this->assertSame('FORBIDDEN', $array['errors'][0]['code']);

        // The new translation must not have been persisted with the forbidden value.
        $reloaded = $this->storage->load($entity->id());
        self::assertInstanceOf(TranslatableTestEntity::class, $reloaded);
        if ($reloaded->hasTranslation('es')) {
            $this->assertNotSame('leaked', $reloaded->getTranslation('es')->get('secret'));
        }
    }

    #[Test]
    public function updateAllowsNonForbiddenFieldEdit(): void
    {
        $entity = $this->createEntityWithFrTranslation();

        $data = ['data' => ['attributes' => ['title' => 'Salut']]];
        $doc = $this->controller->update($this->makeRequest(), 'article', $entity->id(), 'fr', $data);

        $this->assertNotSame(403, $doc->statusCode, 'A non-forbidden field edit must not be blocked by the gate.');
        $array = $doc->toArray();
        $this->assertArrayHasKey('data', $array);
        $this->assertSame('Salut', $array['data']['attributes']['title']);
    }

    private function createEntityWithFrTranslation(): TranslatableTestEntity
    {
        /** @var TranslatableTestEntity $entity */
        $entity = new TranslatableTestEntity(
            values: ['title' => 'Hello', 'langcode' => 'en'],
            entityTypeId: 'article',
        );
        $fr = $entity->addTranslation('fr');
        $fr->set('title', 'Bonjour');
        $this->storage->save($entity);

        return $entity;
    }

    private function makeRequest(): Request
    {
        $request = new Request();
        $request->attributes->set('_account', $this->makeAccount(42));

        return $request;
    }

    private function makeAccount(int $id): AccountInterface
    {
        return new class($id) implements AccountInterface {
            public function __construct(private int $id) {}

            public function id(): int|string
            {
                return $this->id;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    /**
     * A policy that allows every entity-level operation but FORBIDS field-edit
     * on $forbiddenField. Implements the intersection
     * AccessPolicyInterface & FieldAccessPolicyInterface (anonymous class — the
     * intersection cannot be mocked).
     */
    private function fieldGatingPolicy(string $forbiddenField): AccessPolicyInterface
    {
        return new class($forbiddenField) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(private string $forbiddenField) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'edit' && $fieldName === $this->forbiddenField) {
                    return AccessResult::forbidden('Field is privileged.');
                }

                return AccessResult::neutral();
            }
        };
    }
}

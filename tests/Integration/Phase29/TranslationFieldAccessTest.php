<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase29;

use PHPUnit\Framework\Attributes\CoversNothing;
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
 * WP4 twin-leak regression (audit-remediation batch 2026-07-02 R2).
 *
 * {@see TranslationController} serialized every translation CRUD response with
 * `$this->serializer->serialize($translation)` — no access handler, no account —
 * so {@see ResourceSerializer::serialize()} skipped the dynamic per-account
 * field filter and applied only the static internal-field strip. This is the
 * same ResourceSerializer per-account-filter-skip class as the SSR
 * `EntityMarkdownPresenter` leak (WP4): a field a {@see FieldAccessPolicyInterface}
 * forbids `view` for the requesting account still appeared in every translation
 * response (index/show/store/update), reachable by any account with entity
 * view/create/update access.
 *
 * These tests boot the controller with a policy that ALLOWS entity-level
 * view/update (so `checkAccess()` passes) but FORBIDS `view` on a `secret`
 * field. Pre-fix the field leaked (serialize received no account); post-fix the
 * controller threads the same account `checkAccess()` authorized, together with
 * `$this->accessHandler`, into serialize() so the field is filtered.
 */
#[CoversNothing]
final class TranslationFieldAccessTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn(\Waaseyaa\Entity\EntityTypeInterface $definition) => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TranslatableTestEntity::class,
            keys: TranslatableTestEntity::definitionKeys(),
            translatable: true,
        ));
    }

    #[Test]
    public function indexOmitsFieldForbiddenForTheRequestingAccount(): void
    {
        $entity = $this->createEntityWithFrTranslation();
        $account = $this->makeAccount(10);
        $controller = $this->makeController($this->forbidSecretViewPolicy());

        $doc = $controller->index($this->makeRequest($account), 'article', $entity->id());
        $json = json_encode($doc->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('top-secret', $json, 'forbidden field value must not leak');
        self::assertStringNotContainsString('secret', $json, 'forbidden field name must not leak');
        self::assertStringContainsString('public-body', $json, 'a non-restricted field must still be present');
    }

    #[Test]
    public function showOmitsFieldForbiddenForTheRequestingAccount(): void
    {
        $entity = $this->createEntityWithFrTranslation();
        $account = $this->makeAccount(10);
        $controller = $this->makeController($this->forbidSecretViewPolicy());

        $doc = $controller->show($this->makeRequest($account), 'article', $entity->id(), 'fr');
        $array = $doc->toArray();
        $attributes = $array['data']['attributes'] ?? [];

        self::assertArrayNotHasKey('secret', $attributes);
        self::assertArrayHasKey('body', $attributes);
        self::assertSame('public-body', $attributes['body']);
    }

    #[Test]
    public function updateResponseOmitsFieldForbiddenForTheRequestingAccount(): void
    {
        $entity = $this->createEntityWithFrTranslation();
        $account = $this->makeAccount(10);
        $controller = $this->makeController($this->forbidSecretViewPolicy());

        // Update only a permitted field; the response must still filter `secret`.
        $data = ['data' => ['attributes' => ['body' => 'edited-body']]];
        $doc = $controller->update($this->makeRequest($account), 'article', $entity->id(), 'fr', $data);
        $array = $doc->toArray();
        $attributes = $array['data']['attributes'] ?? [];

        self::assertSame(200, $doc->statusCode);
        self::assertArrayNotHasKey('secret', $attributes);
        self::assertArrayHasKey('body', $attributes);
        self::assertSame('edited-body', $attributes['body']);
    }

    /**
     * Baseline: without the per-account filter (serialize called with no
     * account, the pre-fix code path) the forbidden field IS present — proving
     * the omission above is caused by the threaded account, not by the fixture.
     */
    #[Test]
    public function serializerWithoutAccountLeaksTheForbiddenField(): void
    {
        $entity = $this->createEntityWithFrTranslation();
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $attributes = $serializer->serialize($entity->getTranslation('fr'))->attributes;

        self::assertArrayHasKey('secret', $attributes, 'pre-fix static-only path leaks the restricted field');
        self::assertSame('top-secret', $attributes['secret']);
    }

    // --- Helpers ---

    private function createEntityWithFrTranslation(): TranslatableTestEntity
    {
        $entity = new TranslatableTestEntity(
            values: ['title' => 'Hello', 'body' => 'public-body', 'secret' => 'top-secret', 'langcode' => 'en'],
            entityTypeId: 'article',
        );
        $fr = $entity->addTranslation('fr');
        $fr->set('title', 'Bonjour');
        $fr->set('body', 'public-body');
        $fr->set('secret', 'top-secret');
        $this->storage->save($entity);

        return $entity;
    }

    private function makeController(AccessPolicyInterface $policy): TranslationController
    {
        return new TranslationController(
            $this->entityTypeManager,
            new EntityAccessHandler([$policy]),
            new ResourceSerializer($this->entityTypeManager),
        );
    }

    private function makeRequest(AccountInterface $account): Request
    {
        $request = new Request();
        $request->attributes->set('_account', $account);

        return $request;
    }

    private function makeAccount(int $id): AccountInterface
    {
        return new class ($id) implements AccountInterface {
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
                return [];
            }

            public function isAuthenticated(): bool
            {
                return $this->id !== 0;
            }
        };
    }

    /**
     * Allows entity-level view/create/update (so checkAccess passes) but
     * forbids `view` on the `secret` field.
     */
    private function forbidSecretViewPolicy(): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                return $fieldName === 'secret' && $operation === 'view'
                    ? AccessResult::forbidden()
                    : AccessResult::neutral();
            }
        };
    }
}

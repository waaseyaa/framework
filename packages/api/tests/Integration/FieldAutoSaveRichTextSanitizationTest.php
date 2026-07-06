<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\FieldAutoSaveController;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * R13 WP2 (audit A11, SECURITY) exploit test: FieldAutoSaveController echoes
 * the just-saved value back in its 200 response
 * (`'attributes' => [$key => $entity->get($key)]`). For a text_long field
 * this is a THIRD unpatched route to the same cross-admin stored XSS unless
 * the echoed value is sanitized like the JSON:API/GraphQL read paths.
 *
 * Pre-fix: RED (the payload round-trips into the auto-save response body
 * untouched). Post-fix: the echoed value is sanitized.
 *
 * #[CoversNothing] -- boundary test across FieldAutoSaveController + storage,
 * not a single-unit test.
 */
#[CoversNothing]
final class FieldAutoSaveRichTextSanitizationTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private FieldDefinitionRegistry $fieldRegistry;
    private EntityAccessHandler $allowAllHandler;
    private AccountInterface $account;

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
        ));

        $this->fieldRegistry = new FieldDefinitionRegistry();
        $this->fieldRegistry->registerBundleFields('article', 'article', [
            new FieldDefinition(
                name: 'body',
                type: 'text_long',
                targetEntityTypeId: 'article',
                targetBundle: 'article',
                label: 'Body',
            ),
        ]);

        $allowAllPolicy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        };

        $this->allowAllHandler = new EntityAccessHandler([$allowAllPolicy]);

        $this->account = new class implements AccountInterface {
            public function id(): int|string
            {
                return 42;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }
        };
    }

    #[Test]
    public function autoSaveResponseNeutralizesScriptTagInEchoedValue(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Original', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = new FieldAutoSaveController($this->entityTypeManager, $this->allowAllHandler, $this->fieldRegistry);
        $request = $this->makePutRequest($entityId, 'body', '<p>hi</p><script>alert(document.cookie)</script>');

        $response = $controller->update($request, 'article', $entityId, 'body');
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $served = $body['data']['attributes']['body'];

        $this->assertStringNotContainsString(
            '<script',
            $served,
            'FieldAutoSaveController echoes the saved value back on 200 -- a text_long field must be '
            . 'sanitized in that echo, the same as the JSON:API/GraphQL read paths.',
        );
        $this->assertStringContainsString('<p>hi</p>', $served);

        // Non-lossy at rest: the stored value is untouched.
        $reloaded = $this->storage->load($entity->id());
        $this->assertSame('<p>hi</p><script>alert(document.cookie)</script>', $reloaded->get('body'));
    }

    #[Test]
    public function autoSaveResponseNeutralizesOnerrorAttribute(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Original', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = new FieldAutoSaveController($this->entityTypeManager, $this->allowAllHandler, $this->fieldRegistry);
        $request = $this->makePutRequest($entityId, 'body', '<img src=x onerror=alert(1)>');

        $response = $controller->update($request, 'article', $entityId, 'body');
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $served = $body['data']['attributes']['body'];

        $this->assertStringNotContainsString('onerror', $served);
        $this->assertStringNotContainsString('alert(1)', $served);
    }

    private function makePutRequest(string $entityId, string $key, string $value): Request
    {
        $request = Request::create(
            "/api/article/{$entityId}/field/{$key}",
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['value' => $value], JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_account', $this->account);

        return $request;
    }

    private function createSavedEntity(array $values): TestEntity
    {
        /** @var TestEntity $entity */
        $entity = $this->storage->create($values);
        $this->storage->save($entity);

        return $entity;
    }
}

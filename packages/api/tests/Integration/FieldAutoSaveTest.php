<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Controller\FieldAutoSaveController;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Integration tests for FieldAutoSaveController.
 *
 * Covers all status codes from contracts/README.md F3:
 *   200 — happy path + idempotency
 *   401 — not authenticated
 *   403 — entity-level access denied
 *   403 — field-level access denied
 *   404 — entity not found
 *   404 — field key not registered
 *   415 — wrong Content-Type
 *   422 — body too large (Content-Length guard)
 *   422 — malformed JSON
 *   422 — missing/non-string value
 *
 * Scope notes:
 *   - Auth (401) is tested via missing _account attribute on the Request.
 *   - 403 entity/field paths use inline anonymous policy implementations.
 *   - Session middleware integration (setting _account from session cookie) is
 *     covered by WP10 end-to-end tests; here _account is set directly.
 */
#[CoversClass(FieldAutoSaveController::class)]
final class FieldAutoSaveTest extends TestCase
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
                name: 'title',
                type: 'string',
                targetEntityTypeId: 'article',
                targetBundle: 'article',
                label: 'Title',
            ),
            new FieldDefinition(
                name: 'body',
                type: 'text',
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

        $this->account = new AuthorizationPrincipal(42, true, ['administrator'], [], 'test');
    }

    // --- Happy path ---

    #[Test]
    public function happyPathReturns200WithPersistedValue(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Original', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);
        $request = $this->makePutRequest($entityId, 'title', 'Updated Title', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($entityId, $body['data']['id']);
        $this->assertSame('article', $body['data']['type']);
        $this->assertSame('Updated Title', $body['data']['attributes']['title']);

        // Verify persistence.
        $reloaded = $this->storage->load($entity->id());
        $this->assertSame('Updated Title', $reloaded->get('title'));
    }

    #[Test]
    public function idempotentPutsConvergeToSameValue(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Original', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);

        $firstRequest = $this->makePutRequest($entityId, 'title', 'Idempotent Value', $this->account);
        $firstResponse = $controller->update($firstRequest, 'article', $entityId, 'title');

        $secondRequest = $this->makePutRequest($entityId, 'title', 'Idempotent Value', $this->account);
        $secondResponse = $controller->update($secondRequest, 'article', $entityId, 'title');

        $this->assertSame(200, $firstResponse->getStatusCode());
        $this->assertSame(200, $secondResponse->getStatusCode());

        $firstBody = json_decode($firstResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $secondBody = json_decode($secondResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($firstBody['data']['attributes']['title'], $secondBody['data']['attributes']['title']);

        // Entity state is the last-written value.
        $reloaded = $this->storage->load($entity->id());
        $this->assertSame('Idempotent Value', $reloaded->get('title'));
    }

    // --- 401 unauthenticated ---

    #[Test]
    public function missingAccountReturns401(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Secret', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);
        // No _account attribute on the request.
        $request = $this->makeRawPutRequest($entityId, 'title', '{"value": "test"}');

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('unauthenticated', $body['errors'][0]['code']);
    }

    // --- 403 entity-level ---

    #[Test]
    public function forbiddenEntityAccessReturns403(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Protected', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $denyEntityPolicy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('Entity access denied.');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };

        $handler = new EntityAccessHandler([$denyEntityPolicy]);
        $controller = $this->makeController($handler);
        $request = $this->makePutRequest($entityId, 'title', 'Blocked', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('forbidden', $body['errors'][0]['code']);
    }

    // --- 403 field-level ---

    #[Test]
    public function forbiddenFieldAccessReturns403WithFieldForbiddenCode(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Protected', 'type' => 'article']);
        $entityId = (string) $entity->id();

        // Entity access is allowed; field access is forbidden.
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                return AccessResult::neutral();
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('Field write forbidden.');
            }
        };

        $handler = new EntityAccessHandler([$policy]);
        $controller = $this->makeController($handler);
        $request = $this->makePutRequest($entityId, 'title', 'Blocked', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('field_forbidden', $body['errors'][0]['code']);
    }

    // --- 404 entity not found ---

    #[Test]
    public function missingEntityReturns404(): void
    {
        $controller = $this->makeController($this->allowAllHandler);
        $request = $this->makePutRequest('9999', 'title', 'Value', $this->account);

        $response = $controller->update($request, 'article', '9999', 'title');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('entity_not_found', $body['errors'][0]['code']);
    }

    // --- 404 field not registered ---

    #[Test]
    public function unknownFieldKeyReturns404(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Exists', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);
        $request = $this->makePutRequest($entityId, 'nonexistent_field', 'Value', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'nonexistent_field');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('field_not_registered', $body['errors'][0]['code']);
    }

    /**
     * CW-v1 option-1 PR-4 (findings #1/#2) regression pin: FieldAutoSaveController
     * is a per-field endpoint that already allowlists against the bundle
     * field registry (step 5, `$allFields[$key]` — declared fields only), so
     * `published_revision_id` — a real base column with no field definition
     * — 404s here exactly like any other undeclared key. Pinning this so a
     * future refactor cannot silently reopen findings #1/#2 on this surface.
     */
    #[Test]
    public function publishedRevisionIdKeyReturns404(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Exists', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);
        $request = $this->makePutRequest($entityId, 'published_revision_id', '99', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'published_revision_id');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('field_not_registered', $body['errors'][0]['code']);
    }

    // --- 415 wrong Content-Type ---

    #[Test]
    public function wrongContentTypeReturns415(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Test', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);
        $request = Request::create(
            "/api/article/{$entityId}/field/title",
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            '{"value": "hello"}',
        );
        $request->attributes->set('_account', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(415, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('unsupported_media_type', $body['errors'][0]['code']);
    }

    // --- 422 oversize body (Content-Length guard) ---

    #[Test]
    public function oversizeContentLengthReturns422WithoutReadingBody(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Test', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler, maxBodyBytes: 64);
        $request = Request::create(
            "/api/article/{$entityId}/field/title",
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '65536'],
            '{"value": "x"}',
        );
        $request->attributes->set('_account', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('payload_too_large', $body['errors'][0]['code']);
    }

    // --- 422 malformed JSON ---

    #[Test]
    public function malformedJsonReturns422(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Test', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);
        $request = $this->makeRawPutRequest($entityId, 'title', '{not json');
        $request->attributes->set('_account', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('malformed_json', $body['errors'][0]['code']);
    }

    // --- 422 missing value key ---

    #[Test]
    public function missingValueKeyReturns422(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Test', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);
        $request = $this->makeRawPutRequest($entityId, 'title', '{}');
        $request->attributes->set('_account', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('malformed_body', $body['errors'][0]['code']);
    }

    #[Test]
    public function nonStringValueReturns422(): void
    {
        $entity = $this->createSavedEntity(['title' => 'Test', 'type' => 'article']);
        $entityId = (string) $entity->id();

        $controller = $this->makeController($this->allowAllHandler);
        $request = $this->makeRawPutRequest($entityId, 'title', '{"value": 42}');
        $request->attributes->set('_account', $this->account);

        $response = $controller->update($request, 'article', $entityId, 'title');

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('malformed_body', $body['errors'][0]['code']);
    }

    // --- Helpers ---

    private function makeController(EntityAccessHandler $handler, int $maxBodyBytes = 65536): FieldAutoSaveController
    {
        return new FieldAutoSaveController(
            $this->entityTypeManager,
            $handler,
            $this->fieldRegistry,
            $maxBodyBytes,
        );
    }

    private function makePutRequest(string $entityId, string $key, string $value, AccountInterface $account): Request
    {
        $request = $this->makeRawPutRequest($entityId, $key, json_encode(['value' => $value], JSON_THROW_ON_ERROR));
        $request->attributes->set('_account', $account);

        return $request;
    }

    private function makeRawPutRequest(string $entityId, string $key, string $body): Request
    {
        return Request::create(
            "/api/article/{$entityId}/field/{$key}",
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        );
    }

    private function createSavedEntity(array $values): TestEntity
    {
        /** @var TestEntity $entity */
        $entity = $this->storage->create($values);
        $this->storage->save($entity);

        return $entity;
    }
}

# Field-Access Wiring Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Wire the existing field-level access substrate into `public/index.php` so that JSON:API serialization and schema generation actually use field-access checks at runtime.

**Architecture:** The handler (`EntityAccessHandler`), serializer (`ResourceSerializer`), controller (`JsonApiController`), and schema presenter (`SchemaPresenter`) already accept optional `?EntityAccessHandler` + `?AccountInterface` parameters and implement filtering/validation. The only missing piece is passing these values from the front controller. `SchemaController` also needs constructor injection to forward context to the presenter.

**Tech Stack:** PHP 8.3, PHPUnit 10.5, Symfony HttpFoundation

---

### Task 1: SchemaController — add access context constructor params

**Files:**
- Modify: `packages/api/src/Controller/SchemaController.php`

**Step 1: Write the failing test**

Add a new test to `packages/api/tests/Unit/Controller/SchemaControllerTest.php`:

```php
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

#[Test]
public function showAppliesFieldAccessWhenContextProvided(): void
{
    $account = $this->createMock(AccountInterface::class);

    $policy = new class () implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
            return $entityTypeId === 'article';
        }

        public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
        {
            // Forbid viewing uuid, forbid editing title.
            if ($fieldName === 'uuid' && $operation === 'view') {
                return AccessResult::forbidden('hidden');
            }
            if ($fieldName === 'title' && $operation === 'edit') {
                return AccessResult::forbidden('read-only');
            }
            return AccessResult::neutral();
        }
    };

    $handler = new EntityAccessHandler([$policy]);
    $controller = new SchemaController(
        $this->entityTypeManager,
        new SchemaPresenter(),
        $handler,
        $account,
    );

    $doc = $controller->show('article');
    $schema = $doc->toArray()['meta']['schema'];

    // uuid is view-denied — omitted entirely.
    $this->assertArrayNotHasKey('uuid', $schema['properties']);

    // title is edit-denied — present but marked restricted.
    $this->assertArrayHasKey('title', $schema['properties']);
    $this->assertTrue($schema['properties']['title']['readOnly']);
    $this->assertTrue($schema['properties']['title']['x-access-restricted']);

    // id is still present (not restricted by policy).
    $this->assertArrayHasKey('id', $schema['properties']);
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter showAppliesFieldAccessWhenContextProvided`
Expected: Error — `SchemaController::__construct()` does not accept 4 arguments.

**Step 3: Write minimal implementation**

In `packages/api/src/Controller/SchemaController.php`:

1. Add use statements:
```php
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
```

2. Update constructor:
```php
public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SchemaPresenter $schemaPresenter,
    private readonly ?EntityAccessHandler $accessHandler = null,
    private readonly ?AccountInterface $account = null,
) {}
```

3. Update `show()` — replace line 38 (`$schema = $this->schemaPresenter->present($entityType);`) with:
```php
$entity = null;
if ($this->accessHandler !== null && $this->account !== null) {
    $class = $entityType->getClass();
    $entity = new $class([]);
}

$schema = $this->schemaPresenter->present(
    $entityType,
    [],
    $entity,
    $this->accessHandler,
    $this->account,
);
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter showAppliesFieldAccessWhenContextProvided`
Expected: PASS

**Step 5: Run full SchemaController test suite**

Run: `./vendor/bin/phpunit --filter SchemaControllerTest`
Expected: All 5 tests pass (4 existing + 1 new). Existing tests construct SchemaController with 2 args — backward compat via nullable defaults.

**Step 6: Commit**

```bash
git add packages/api/src/Controller/SchemaController.php packages/api/tests/Unit/Controller/SchemaControllerTest.php
git commit -m "feat: add field-access context to SchemaController"
```

---

### Task 2: Wire access handler and account in public/index.php

**Files:**
- Modify: `public/index.php`

**Step 1: Add use statement**

After the existing use statements (line 66 area), add:
```php
use Waaseyaa\Access\EntityAccessHandler;
```

**Step 2: Create access handler and extract account**

After the authorization pipeline block (after line 255, the closing `}` of the auth status check), add:
```php
// --- Field-level access context ------------------------------------------------

$account = $httpRequest->attributes->get('_account');
$accessHandler = new EntityAccessHandler([]);
```

**Step 3: Pass context to SchemaController**

Replace the SchemaController instantiation (line 364):

Before:
```php
$schemaController = new SchemaController($entityTypeManager, $schemaPresenter);
```

After:
```php
$schemaController = new SchemaController($entityTypeManager, $schemaPresenter, $accessHandler, $account);
```

**Step 4: Pass context to JsonApiController**

Replace the JsonApiController instantiation (line 371):

Before:
```php
$jsonApiController = new JsonApiController($entityTypeManager, $serializer);
```

After:
```php
$jsonApiController = new JsonApiController($entityTypeManager, $serializer, $accessHandler, $account);
```

**Step 5: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass. No behavior change because `EntityAccessHandler([])` has zero policies — all fields return Neutral, which is non-forbidden.

**Step 6: Commit**

```bash
git add public/index.php
git commit -m "feat: wire field-access handler into front controller"
```

---

### Task 3: Integration test — wiring round-trip with prototype entity

**Files:**
- Create: `tests/Integration/Phase7/FieldAccessWiringTest.php`

This test verifies the SchemaController + prototype entity path works end-to-end, complementing the existing Phase6 tests that tested the substrate in isolation.

**Step 1: Write the integration test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Integration test: field-access wiring through controllers.
 *
 * Verifies that when EntityAccessHandler and AccountInterface are injected
 * into controllers (as public/index.php now does), field-level access
 * checks are active in both JSON:API responses and schema generation.
 */
#[CoversNothing]
final class FieldAccessWiringTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityType $entityType;
    private AccountInterface $account;
    private EntityAccessHandler $accessHandler;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $this->entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
        $this->entityTypeManager->registerEntityType($this->entityType);

        $this->account = $this->createMock(AccountInterface::class);

        // Policy: forbid viewing 'secret', forbid editing 'status'.
        $policy = new class () implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                return $entityTypeId === 'article';
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'secret' && $operation === 'view') {
                    return AccessResult::forbidden('hidden');
                }
                if ($fieldName === 'status' && $operation === 'edit') {
                    return AccessResult::forbidden('restricted');
                }
                return AccessResult::neutral();
            }
        };

        $this->accessHandler = new EntityAccessHandler([$policy]);
    }

    #[Test]
    public function schemaControllerAppliesFieldAccess(): void
    {
        $controller = new SchemaController(
            $this->entityTypeManager,
            new SchemaPresenter(),
            $this->accessHandler,
            $this->account,
        );

        $doc = $controller->show('article');
        $schema = $doc->toArray()['meta']['schema'];

        // System properties (id, uuid, title) are present — no policy restricts them.
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
    }

    #[Test]
    public function jsonApiControllerAndSchemaControllerShareHandler(): void
    {
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $jsonApi = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $this->account,
        );

        $schema = new SchemaController(
            $this->entityTypeManager,
            new SchemaPresenter(),
            $this->accessHandler,
            $this->account,
        );

        // Create entity with all fields.
        $entity = $this->storage->create([
            'title' => 'Test',
            'secret' => 'classified',
            'status' => 'draft',
            'body' => 'content',
        ]);
        $this->storage->save($entity);

        // JSON:API GET omits secret.
        $apiDoc = $jsonApi->show('article', $entity->id());
        $attrs = $apiDoc->toArray()['data']['attributes'];
        $this->assertArrayNotHasKey('secret', $attrs);
        $this->assertArrayHasKey('body', $attrs);

        // JSON:API PATCH rejects status edit.
        $patchDoc = $jsonApi->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);
        $this->assertSame(403, $patchDoc->statusCode);

        // Schema endpoint works with same handler.
        $schemaDoc = $schema->show('article');
        $this->assertSame(200, $schemaDoc->statusCode);
    }

    #[Test]
    public function emptyPolicyArrayAllowsEverything(): void
    {
        $emptyHandler = new EntityAccessHandler([]);
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $jsonApi = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $emptyHandler,
            $this->account,
        );

        $entity = $this->storage->create([
            'title' => 'Open',
            'secret' => 'visible',
            'status' => 'draft',
        ]);
        $this->storage->save($entity);

        // All fields present — empty handler = neutral = allowed.
        $doc = $jsonApi->show('article', $entity->id());
        $attrs = $doc->toArray()['data']['attributes'];
        $this->assertArrayHasKey('secret', $attrs);
        $this->assertArrayHasKey('status', $attrs);

        // PATCH succeeds.
        $patchDoc = $jsonApi->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);
        $this->assertSame(200, $patchDoc->statusCode);
    }
}
```

**Step 2: Ensure tests/Integration/Phase7 directory exists and phpunit.xml.dist includes it**

Check `phpunit.xml.dist` for the integration testsuite path pattern. It should already glob `tests/Integration/` recursively.

**Step 3: Run the new integration tests**

Run: `./vendor/bin/phpunit --filter FieldAccessWiringTest`
Expected: All 3 tests pass.

**Step 4: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (existing + new).

**Step 5: Commit**

```bash
git add tests/Integration/Phase7/FieldAccessWiringTest.php
git commit -m "test: integration tests for field-access controller wiring"
```

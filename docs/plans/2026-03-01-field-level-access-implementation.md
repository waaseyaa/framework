# Field-Level Access Control — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add field-level access control so policies can restrict which fields a user can view or edit on entities, enforced in JSON:API responses, write operations, and admin form schemas.

**Architecture:** New `FieldAccessPolicyInterface` (separate from `AccessPolicyInterface`) discovered via same `#[AccessPolicy]` attribute. `EntityAccessHandler` gains `checkFieldAccess()` and `filterFields()` methods. Open-by-default: NEUTRAL = accessible, FORBIDDEN = denied.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5 (attributes), Nuxt 3 / Vue 3 / TypeScript

**Design doc:** `docs/plans/2026-03-01-field-level-access-design.md`

---

### Task 1: Create FieldAccessPolicyInterface

**Files:**
- Create: `packages/access/src/FieldAccessPolicyInterface.php`

**Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/**
 * Checks access for a specific field on an entity.
 *
 * Policies implement this interface alongside AccessPolicyInterface to
 * opt into field-level access control. The same #[AccessPolicy] attribute
 * and appliesTo() method scope field checks to entity types.
 */
interface FieldAccessPolicyInterface
{
    /**
     * Check access for a specific field on an entity.
     *
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $fieldName The field name being checked.
     * @param string           $operation The operation: 'view' or 'edit'.
     * @param AccountInterface $account   The account requesting access.
     */
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult;
}
```

**Step 2: Verify autoloading**

Run: `./vendor/bin/phpunit --filter Phase1 2>&1 | head -5`

This just confirms no syntax errors. The interface has no tests of its own — it's a contract tested through EntityAccessHandler in Task 2.

**Step 3: Commit**

```
git add packages/access/src/FieldAccessPolicyInterface.php
git commit -m "feat(access): add FieldAccessPolicyInterface"
```

---

### Task 2: Add checkFieldAccess() to EntityAccessHandler

**Files:**
- Modify: `packages/access/src/EntityAccessHandler.php:16-95`
- Create: `packages/access/tests/Unit/EntityAccessHandlerFieldAccessTest.php`

**Step 1: Write the failing tests**

Create `packages/access/tests/Unit/EntityAccessHandlerFieldAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

#[CoversClass(EntityAccessHandler::class)]
final class EntityAccessHandlerFieldAccessTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createEntity(string $typeId = 'node'): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($typeId);
        return $entity;
    }

    private function createAccount(): AccountInterface
    {
        return $this->createMock(AccountInterface::class);
    }

    /**
     * Creates a policy implementing both interfaces.
     *
     * AccessPolicyInterface cannot be mocked with createMock on an anonymous class
     * that also implements FieldAccessPolicyInterface, so we build a stub manually.
     */
    private function createFieldPolicy(
        string $entityTypeId,
        AccessResult $fieldResult,
    ): AccessPolicyInterface&FieldAccessPolicyInterface {
        return new class ($entityTypeId, $fieldResult) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly AccessResult $fieldResult,
            ) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return $this->fieldResult;
            }
        };
    }

    /**
     * Creates a field policy with per-field logic.
     */
    private function createConditionalFieldPolicy(
        string $entityTypeId,
        string $targetField,
        string $targetOperation,
        AccessResult $result,
    ): AccessPolicyInterface&FieldAccessPolicyInterface {
        return new class ($entityTypeId, $targetField, $targetOperation, $result) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly string $targetField,
                private readonly string $targetOperation,
                private readonly AccessResult $result,
            ) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === $this->targetField && $operation === $this->targetOperation) {
                    return $this->result;
                }
                return AccessResult::neutral();
            }
        };
    }

    // ---------------------------------------------------------------
    // checkFieldAccess() tests
    // ---------------------------------------------------------------

    #[Test]
    public function checkFieldAccessNoPoliciesReturnsNeutral(): void
    {
        $handler = new EntityAccessHandler();
        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'body',
            'view',
            $this->createAccount(),
        );

        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function checkFieldAccessSkipsPoliciesWithoutFieldInterface(): void
    {
        // A policy that only implements AccessPolicyInterface (no field access).
        $entityOnlyPolicy = $this->createMock(AccessPolicyInterface::class);
        $entityOnlyPolicy->method('appliesTo')->willReturn(true);

        $handler = new EntityAccessHandler([$entityOnlyPolicy]);
        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'body',
            'view',
            $this->createAccount(),
        );

        // Should be Neutral since the only policy doesn't implement FieldAccessPolicyInterface.
        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function checkFieldAccessAllowed(): void
    {
        $policy = $this->createFieldPolicy('node', AccessResult::allowed('has permission'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'body',
            'view',
            $this->createAccount(),
        );

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function checkFieldAccessForbidden(): void
    {
        $policy = $this->createFieldPolicy('node', AccessResult::forbidden('secret field'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'secret',
            'view',
            $this->createAccount(),
        );

        $this->assertTrue($result->isForbidden());
        $this->assertSame('secret field', $result->reason);
    }

    #[Test]
    public function checkFieldAccessForbiddenWinsOverAllowed(): void
    {
        $handler = new EntityAccessHandler([
            $this->createFieldPolicy('node', AccessResult::allowed('yes')),
            $this->createFieldPolicy('node', AccessResult::forbidden('no')),
        ]);

        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'status',
            'edit',
            $this->createAccount(),
        );

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function checkFieldAccessFiltersByEntityType(): void
    {
        // Policy applies to 'user', not 'node'.
        $policy = $this->createFieldPolicy('user', AccessResult::forbidden('should not apply'));
        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->checkFieldAccess(
            $this->createEntity('node'),
            'body',
            'view',
            $this->createAccount(),
        );

        $this->assertTrue($result->isNeutral());
    }

    #[Test]
    public function checkFieldAccessForbiddenShortCircuits(): void
    {
        $handler = new EntityAccessHandler([
            $this->createFieldPolicy('node', AccessResult::forbidden('stop')),
            $this->createFieldPolicy('node', AccessResult::allowed('go')),
        ]);

        $result = $handler->checkFieldAccess(
            $this->createEntity(),
            'status',
            'edit',
            $this->createAccount(),
        );

        $this->assertTrue($result->isForbidden());
        $this->assertSame('stop', $result->reason);
    }

    #[Test]
    public function checkFieldAccessPassesFieldNameAndOperation(): void
    {
        $policy = $this->createConditionalFieldPolicy('node', 'status', 'edit', AccessResult::forbidden('no edit status'));
        $handler = new EntityAccessHandler([$policy]);

        // status + edit → forbidden
        $result = $handler->checkFieldAccess($this->createEntity(), 'status', 'edit', $this->createAccount());
        $this->assertTrue($result->isForbidden());

        // status + view → neutral (no match)
        $result = $handler->checkFieldAccess($this->createEntity(), 'status', 'view', $this->createAccount());
        $this->assertTrue($result->isNeutral());

        // body + edit → neutral (no match)
        $result = $handler->checkFieldAccess($this->createEntity(), 'body', 'edit', $this->createAccount());
        $this->assertTrue($result->isNeutral());
    }

    // ---------------------------------------------------------------
    // filterFields() tests
    // ---------------------------------------------------------------

    #[Test]
    public function filterFieldsReturnsAllWhenNoPolicies(): void
    {
        $handler = new EntityAccessHandler();
        $fields = $handler->filterFields(
            $this->createEntity(),
            ['title', 'body', 'status'],
            'view',
            $this->createAccount(),
        );

        $this->assertSame(['title', 'body', 'status'], $fields);
    }

    #[Test]
    public function filterFieldsRemovesForbiddenFields(): void
    {
        $policy = $this->createConditionalFieldPolicy('node', 'secret', 'view', AccessResult::forbidden('hidden'));
        $handler = new EntityAccessHandler([$policy]);

        $fields = $handler->filterFields(
            $this->createEntity(),
            ['title', 'body', 'secret', 'status'],
            'view',
            $this->createAccount(),
        );

        $this->assertSame(['title', 'body', 'status'], $fields);
    }

    #[Test]
    public function filterFieldsUsesCorrectOperation(): void
    {
        // Forbid editing 'status', but viewing is fine.
        $policy = $this->createConditionalFieldPolicy('node', 'status', 'edit', AccessResult::forbidden('no'));
        $handler = new EntityAccessHandler([$policy]);

        $viewFields = $handler->filterFields($this->createEntity(), ['title', 'status'], 'view', $this->createAccount());
        $editFields = $handler->filterFields($this->createEntity(), ['title', 'status'], 'edit', $this->createAccount());

        $this->assertSame(['title', 'status'], $viewFields);
        $this->assertSame(['title'], $editFields);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/EntityAccessHandlerFieldAccessTest.php`

Expected: FAIL — `checkFieldAccess()` and `filterFields()` methods don't exist yet.

**Step 3: Implement checkFieldAccess() and filterFields()**

Add to `packages/access/src/EntityAccessHandler.php` after the `checkCreateAccess()` method (after line 94):

```php
    /**
     * Check access for a specific field on an entity.
     *
     * Only policies implementing FieldAccessPolicyInterface participate.
     * Results are combined using OR logic, with Forbidden short-circuiting.
     *
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $fieldName The field name being checked.
     * @param string           $operation The operation: 'view' or 'edit'.
     * @param AccountInterface $account   The account requesting access.
     */
    public function checkFieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        $result = AccessResult::neutral('No field access policy provided an opinion.');
        $entityTypeId = $entity->getEntityTypeId();

        foreach ($this->policies as $policy) {
            if (!$policy->appliesTo($entityTypeId)) {
                continue;
            }
            if (!$policy instanceof FieldAccessPolicyInterface) {
                continue;
            }

            $policyResult = $policy->fieldAccess($entity, $fieldName, $operation, $account);
            $result = $result->orIf($policyResult);

            if ($result->isForbidden()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Filter a list of field names, removing those that are forbidden.
     *
     * @param EntityInterface  $entity     The entity being accessed.
     * @param string[]         $fieldNames The field names to check.
     * @param string           $operation  The operation: 'view' or 'edit'.
     * @param AccountInterface $account    The account requesting access.
     *
     * @return string[] Field names that are not forbidden.
     */
    public function filterFields(
        EntityInterface $entity,
        array $fieldNames,
        string $operation,
        AccountInterface $account,
    ): array {
        return array_values(array_filter(
            $fieldNames,
            fn(string $field): bool => !$this->checkFieldAccess($entity, $field, $operation, $account)->isForbidden(),
        ));
    }
```

Also add the use statement at the top of `EntityAccessHandler.php` — but wait, `FieldAccessPolicyInterface` is in the same namespace (`Waaseyaa\Access`), so no import needed.

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/EntityAccessHandlerFieldAccessTest.php`

Expected: All 10 tests PASS.

**Step 5: Run full access test suite to verify no regressions**

Run: `./vendor/bin/phpunit packages/access/tests/`

Expected: All existing + new tests PASS.

**Step 6: Commit**

```
git add packages/access/src/EntityAccessHandler.php packages/access/tests/Unit/EntityAccessHandlerFieldAccessTest.php
git commit -m "feat(access): add checkFieldAccess() and filterFields() to EntityAccessHandler"
```

---

### Task 3: Add field access filtering to ResourceSerializer

**Files:**
- Modify: `packages/api/src/ResourceSerializer.php:16-88`
- Create: `packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php`

**Step 1: Write the failing tests**

Create `packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(ResourceSerializer::class)]
final class ResourceSerializerFieldAccessTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private ResourceSerializer $serializer;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ));
        $this->serializer = new ResourceSerializer($this->entityTypeManager);
    }

    private function createAccount(): AccountInterface
    {
        return $this->createMock(AccountInterface::class);
    }

    /**
     * Creates a field policy that forbids viewing specific fields.
     */
    private function createViewDenyPolicy(string $entityTypeId, array $deniedFields): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class ($entityTypeId, $deniedFields) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly array $deniedFields,
            ) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view' && in_array($fieldName, $this->deniedFields, true)) {
                    return AccessResult::forbidden("No view access to {$fieldName}");
                }
                return AccessResult::neutral();
            }
        };
    }

    #[Test]
    public function serializeWithoutAccessHandlerReturnsAllFields(): void
    {
        $entity = new TestEntity([
            'id' => 1, 'uuid' => 'uuid-1', 'title' => 'Test',
            'type' => 'blog', 'body' => 'Content', 'secret' => 'classified',
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertArrayHasKey('body', $resource->attributes);
        $this->assertArrayHasKey('secret', $resource->attributes);
    }

    #[Test]
    public function serializeOmitsViewDeniedFields(): void
    {
        $policy = $this->createViewDenyPolicy('article', ['secret']);
        $accessHandler = new EntityAccessHandler([$policy]);
        $account = $this->createAccount();

        $entity = new TestEntity([
            'id' => 1, 'uuid' => 'uuid-1', 'title' => 'Test',
            'type' => 'blog', 'body' => 'Content', 'secret' => 'classified',
        ]);

        $resource = $this->serializer->serialize($entity, $accessHandler, $account);

        $this->assertArrayHasKey('body', $resource->attributes);
        $this->assertArrayNotHasKey('secret', $resource->attributes);
        $this->assertArrayHasKey('title', $resource->attributes);
    }

    #[Test]
    public function serializeOmitsMultipleViewDeniedFields(): void
    {
        $policy = $this->createViewDenyPolicy('article', ['secret', 'internal_notes']);
        $accessHandler = new EntityAccessHandler([$policy]);
        $account = $this->createAccount();

        $entity = new TestEntity([
            'id' => 1, 'uuid' => 'uuid-1', 'title' => 'Test', 'type' => 'blog',
            'body' => 'Content', 'secret' => 'classified', 'internal_notes' => 'private',
        ]);

        $resource = $this->serializer->serialize($entity, $accessHandler, $account);

        $this->assertArrayHasKey('body', $resource->attributes);
        $this->assertArrayNotHasKey('secret', $resource->attributes);
        $this->assertArrayNotHasKey('internal_notes', $resource->attributes);
    }

    #[Test]
    public function serializeCollectionFiltersFieldsPerEntity(): void
    {
        $policy = $this->createViewDenyPolicy('article', ['secret']);
        $accessHandler = new EntityAccessHandler([$policy]);
        $account = $this->createAccount();

        $entities = [
            new TestEntity(['id' => 1, 'uuid' => 'uuid-1', 'title' => 'A', 'secret' => 's1']),
            new TestEntity(['id' => 2, 'uuid' => 'uuid-2', 'title' => 'B', 'secret' => 's2']),
        ];

        $resources = $this->serializer->serializeCollection($entities, $accessHandler, $account);

        $this->assertCount(2, $resources);
        $this->assertArrayNotHasKey('secret', $resources[0]->attributes);
        $this->assertArrayNotHasKey('secret', $resources[1]->attributes);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php`

Expected: FAIL — `serialize()` doesn't accept access handler params yet.

**Step 3: Modify ResourceSerializer**

In `packages/api/src/ResourceSerializer.php`, update the `serialize()` and `serializeCollection()` methods:

Add use statements at top of file (after existing use statements):
```php
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
```

Replace the `serialize()` method signature and body (lines 26-49):
```php
    /**
     * Serialize a single entity to a JsonApiResource.
     *
     * When an access handler and account are provided, fields that the account
     * cannot view are omitted from the attributes.
     */
    public function serialize(
        EntityInterface $entity,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): JsonApiResource {
        $entityTypeId = $entity->getEntityTypeId();
        $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $entityType->getKeys();

        // Use UUID as the resource ID if available, otherwise fall back to entity ID.
        $resourceId = $entity->uuid() !== '' ? $entity->uuid() : (string) $entity->id();

        // Build attributes from entity values, excluding entity keys (id, uuid).
        $allValues = $entity->toArray();
        $excludedFields = $this->getExcludedFields($keys);
        $attributes = array_diff_key($allValues, array_flip($excludedFields));

        // Filter out fields the account cannot view.
        if ($accessHandler !== null && $account !== null) {
            $allowedFields = $accessHandler->filterFields($entity, array_keys($attributes), 'view', $account);
            $attributes = array_intersect_key($attributes, array_flip($allowedFields));
        }

        // Build self link.
        $selfLink = $this->basePath . '/' . $entityTypeId . '/' . $resourceId;

        return new JsonApiResource(
            type: $entityTypeId,
            id: $resourceId,
            attributes: $attributes,
            links: ['self' => $selfLink],
        );
    }
```

Replace the `serializeCollection()` method (lines 57-63):
```php
    /**
     * Serialize a collection of entities to an array of JsonApiResource objects.
     *
     * @param array<EntityInterface> $entities
     * @return array<JsonApiResource>
     */
    public function serializeCollection(
        array $entities,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): array {
        return array_values(array_map(
            fn(EntityInterface $entity): JsonApiResource => $this->serialize($entity, $accessHandler, $account),
            $entities,
        ));
    }
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php`

Expected: All 4 tests PASS.

**Step 5: Run existing ResourceSerializer tests for regressions**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/ResourceSerializerTest.php`

Expected: All existing tests PASS (new params are optional).

**Step 6: Commit**

```
git add packages/api/src/ResourceSerializer.php packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php
git commit -m "feat(api): add field access filtering to ResourceSerializer"
```

---

### Task 4: Add field edit access checks to JsonApiController

**Files:**
- Modify: `packages/api/src/JsonApiController.php:22-368`
- Create: `packages/api/tests/Unit/JsonApiControllerFieldAccessTest.php`

**Step 1: Write the failing tests**

Create `packages/api/tests/Unit/JsonApiControllerFieldAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerFieldAccessTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;
    private AccountInterface $account;
    private JsonApiController $controller;

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
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ));

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
                    return AccessResult::forbidden('No view access to secret');
                }
                if ($fieldName === 'status' && $operation === 'edit') {
                    return AccessResult::forbidden('No edit access to status');
                }
                return AccessResult::neutral();
            }
        };

        $this->accessHandler = new EntityAccessHandler([$policy]);
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $this->account,
        );
    }

    private function createAndSaveEntity(array $values = []): TestEntity
    {
        $entity = $this->storage->create($values);
        $this->storage->save($entity);
        return $entity;
    }

    // --- GET: view-denied fields omitted ---

    #[Test]
    public function showOmitsViewDeniedFields(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'Test', 'body' => 'Content', 'secret' => 'classified',
        ]);

        $doc = $this->controller->show('article', $entity->id());
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('body', $array['data']['attributes']);
        $this->assertArrayNotHasKey('secret', $array['data']['attributes']);
    }

    #[Test]
    public function indexOmitsViewDeniedFields(): void
    {
        $this->createAndSaveEntity(['title' => 'A', 'secret' => 's1']);
        $this->createAndSaveEntity(['title' => 'B', 'secret' => 's2']);

        $doc = $this->controller->index('article');
        $array = $doc->toArray();

        foreach ($array['data'] as $resource) {
            $this->assertArrayNotHasKey('secret', $resource['attributes']);
        }
    }

    // --- PATCH: edit-denied fields rejected ---

    #[Test]
    public function updateRejectsEditDeniedField(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'Test', 'status' => 'draft',
        ]);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertStringContainsString('status', $array['errors'][0]['detail']);
    }

    #[Test]
    public function updateAllowsNonRestrictedFields(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'Test', 'body' => 'Original',
        ]);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['body' => 'Updated'],
            ],
        ]);

        $this->assertSame(200, $doc->statusCode);
    }

    // --- POST: edit-denied fields rejected ---

    #[Test]
    public function storeRejectsEditDeniedField(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New', 'status' => 'published'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertStringContainsString('status', $array['errors'][0]['detail']);
    }

    #[Test]
    public function storeAllowsNonRestrictedFields(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New Article', 'body' => 'Content'],
            ],
        ]);

        $this->assertSame(201, $doc->statusCode);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/JsonApiControllerFieldAccessTest.php`

Expected: FAIL — controller doesn't pass access handler to serializer, doesn't check field edit access.

**Step 3: Modify JsonApiController**

Three changes needed in `packages/api/src/JsonApiController.php`:

**3a.** In the `index()` method, update the serialization call (line 79) to pass access handler:

Replace:
```php
        $resources = $this->serializer->serializeCollection($entities);
```
With:
```php
        $resources = $this->serializer->serializeCollection($entities, $this->accessHandler, $this->account);
```

**3b.** In the `show()` method, update the serialization call (line 151) to pass access handler:

Replace:
```php
        $resource = $this->serializer->serialize($entity);
```
With:
```php
        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);
```

**3c.** In the `store()` method, add field edit access check after the create access check block (after line 199) and before `$storage = ...`:

```php
        // Check field edit access for submitted attributes.
        if ($this->accessHandler !== null && $this->account !== null) {
            foreach (array_keys($attributes) as $fieldName) {
                $fieldResult = $this->accessHandler->checkFieldAccess(
                    $storage->create($attributes),
                    (string) $fieldName,
                    'edit',
                    $this->account,
                );
                if ($fieldResult->isForbidden()) {
                    return $this->errorDocument(
                        JsonApiError::forbidden("No edit access to field '{$fieldName}'."),
                    );
                }
            }
        }
```

Wait — in `store()`, the entity doesn't exist yet. We need a temporary entity for the field access check. The store method already has `$storage` available (line 201). But we need to restructure slightly. Let me reconsider.

Actually, looking at the method more carefully: `$storage` is created on line 201, after the create access check. We need to move `$storage` up. Here's the cleaner approach:

In `store()`, move `$storage = $this->entityTypeManager->getStorage($entityTypeId);` to just after the type mismatch check (after line 186), before the create access check. Then add field edit access after create access:

Replace the `store()` method body from line 188 through line 213 with:

```php
        $attributes = $data['data']['attributes'] ?? [];
        $storage = $this->entityTypeManager->getStorage($entityTypeId);

        // Check create access.
        if ($this->accessHandler !== null && $this->account !== null) {
            $bundle = $attributes['bundle'] ?? $entityTypeId;
            $access = $this->accessHandler->checkCreateAccess($entityTypeId, (string) $bundle, $this->account);
            if (!$access->isAllowed()) {
                return $this->errorDocument(
                    JsonApiError::forbidden("Access denied for creating entity of type '{$entityTypeId}'."),
                );
            }

            // Check field edit access for submitted attributes.
            $tempEntity = $storage->create($attributes);
            foreach (array_keys($attributes) as $fieldName) {
                $fieldResult = $this->accessHandler->checkFieldAccess(
                    $tempEntity,
                    (string) $fieldName,
                    'edit',
                    $this->account,
                );
                if ($fieldResult->isForbidden()) {
                    return $this->errorDocument(
                        JsonApiError::forbidden("No edit access to field '{$fieldName}'."),
                    );
                }
            }
        }

        $entity = $storage->create($attributes);
        $storage->save($entity);

        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);

        return new JsonApiDocument(
            data: $resource,
            links: ['self' => "/api/{$entityTypeId}/{$resource->id}"],
            meta: ['created' => true],
            statusCode: 201,
        );
```

**3d.** In the `update()` method, add field edit access check after the entity-level update access check (after line 272), before the FieldableInterface check:

```php
        // Check field edit access for submitted attributes.
        $attributes = $data['data']['attributes'] ?? [];
        if ($this->accessHandler !== null && $this->account !== null) {
            foreach (array_keys($attributes) as $fieldName) {
                $fieldResult = $this->accessHandler->checkFieldAccess(
                    $entity,
                    (string) $fieldName,
                    'edit',
                    $this->account,
                );
                if ($fieldResult->isForbidden()) {
                    return $this->errorDocument(
                        JsonApiError::forbidden("No edit access to field '{$fieldName}'."),
                    );
                }
            }
        }
```

Then update the existing `$attributes` line (275) — it's now defined above, so remove the duplicate:

Replace:
```php
        // Apply attribute updates.
        $attributes = $data['data']['attributes'] ?? [];
        if (!$entity instanceof FieldableInterface) {
```
With:
```php
        // Apply attribute updates.
        if (!$entity instanceof FieldableInterface) {
```

Also update the `serialize()` call in `update()` (line 287):

Replace:
```php
        $resource = $this->serializer->serialize($entity);
```
With:
```php
        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);
```

And in `store()`, the serialize call (line 205) similarly:

Replace:
```php
        $resource = $this->serializer->serialize($entity);
```
With:
```php
        $resource = $this->serializer->serialize($entity, $this->accessHandler, $this->account);
```

(Already handled in the store replacement block above.)

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/JsonApiControllerFieldAccessTest.php`

Expected: All 6 tests PASS.

**Step 5: Run existing JsonApiController tests for regressions**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/JsonApiControllerTest.php`

Expected: All existing tests PASS (access handler is still optional).

**Step 6: Commit**

```
git add packages/api/src/JsonApiController.php packages/api/tests/Unit/JsonApiControllerFieldAccessTest.php
git commit -m "feat(api): add field edit access checks to JsonApiController"
```

---

### Task 5: Add field access metadata to SchemaPresenter

**Files:**
- Modify: `packages/api/src/Schema/SchemaPresenter.php:20-288`
- Create: `packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php`

**Step 1: Write the failing tests**

Create `packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;

#[CoversClass(SchemaPresenter::class)]
final class SchemaPresenterFieldAccessTest extends TestCase
{
    private SchemaPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new SchemaPresenter();
    }

    private function createEntityType(): EntityType
    {
        return new EntityType(
            id: 'article',
            label: 'Article',
            class: \Waaseyaa\Api\Tests\Fixtures\TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
    }

    private function createEntity(): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('article');
        return $entity;
    }

    private function createAccount(): AccountInterface
    {
        return $this->createMock(AccountInterface::class);
    }

    private function createFieldDefs(): array
    {
        return [
            'body' => ['type' => 'text', 'label' => 'Body'],
            'secret' => ['type' => 'string', 'label' => 'Secret'],
            'status' => ['type' => 'string', 'label' => 'Status'],
        ];
    }

    /**
     * Creates a policy: forbid viewing 'secret', forbid editing 'status'.
     */
    private function createPolicy(): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class () implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                    return AccessResult::forbidden('read-only');
                }
                return AccessResult::neutral();
            }
        };
    }

    #[Test]
    public function presentWithoutAccessContextReturnsAllFields(): void
    {
        $schema = $this->presenter->present($this->createEntityType(), $this->createFieldDefs());

        $this->assertArrayHasKey('body', $schema['properties']);
        $this->assertArrayHasKey('secret', $schema['properties']);
        $this->assertArrayHasKey('status', $schema['properties']);
    }

    #[Test]
    public function presentRemovesViewDeniedFields(): void
    {
        $accessHandler = new EntityAccessHandler([$this->createPolicy()]);

        $schema = $this->presenter->present(
            $this->createEntityType(),
            $this->createFieldDefs(),
            $this->createEntity(),
            $accessHandler,
            $this->createAccount(),
        );

        // 'secret' is view-denied — should be removed entirely.
        $this->assertArrayNotHasKey('secret', $schema['properties']);
        // 'body' and 'status' are view-allowed — should remain.
        $this->assertArrayHasKey('body', $schema['properties']);
        $this->assertArrayHasKey('status', $schema['properties']);
    }

    #[Test]
    public function presentMarksEditDeniedFieldsAsRestricted(): void
    {
        $accessHandler = new EntityAccessHandler([$this->createPolicy()]);

        $schema = $this->presenter->present(
            $this->createEntityType(),
            $this->createFieldDefs(),
            $this->createEntity(),
            $accessHandler,
            $this->createAccount(),
        );

        // 'status' is edit-denied — should be readOnly with x-access-restricted.
        $this->assertTrue($schema['properties']['status']['readOnly'] ?? false);
        $this->assertTrue($schema['properties']['status']['x-access-restricted'] ?? false);

        // 'body' is fully accessible — should NOT have restricted flag.
        $this->assertArrayNotHasKey('readOnly', $schema['properties']['body']);
        $this->assertArrayNotHasKey('x-access-restricted', $schema['properties']['body']);
    }

    #[Test]
    public function presentDoesNotTouchSystemProperties(): void
    {
        $accessHandler = new EntityAccessHandler([$this->createPolicy()]);

        $schema = $this->presenter->present(
            $this->createEntityType(),
            $this->createFieldDefs(),
            $this->createEntity(),
            $accessHandler,
            $this->createAccount(),
        );

        // System properties (id, uuid) should be unchanged.
        $this->assertTrue($schema['properties']['id']['readOnly']);
        $this->assertSame('hidden', $schema['properties']['id']['x-widget']);
        $this->assertArrayNotHasKey('x-access-restricted', $schema['properties']['id']);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php`

Expected: FAIL — `present()` doesn't accept access context params.

**Step 3: Modify SchemaPresenter**

In `packages/api/src/Schema/SchemaPresenter.php`:

Add use statements after the existing use statement (line 7):
```php
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
```

Update the `present()` method signature (lines 95-96) and add field access filtering after the field definitions loop:

Replace:
```php
    public function present(EntityTypeInterface $entityType, array $fieldDefinitions = []): array
```
With:
```php
    public function present(
        EntityTypeInterface $entityType,
        array $fieldDefinitions = [],
        ?EntityInterface $entity = null,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): array
```

Then, after the closing brace of the `if ($fieldDefinitions !== [])` block (after line 133) and before `$schema['properties'] = $properties;` (line 135), insert:

```php
        // Apply field access control if context is available.
        if ($entity !== null && $accessHandler !== null && $account !== null) {
            $systemKeys = array_values($keys);
            foreach ($properties as $fieldName => $property) {
                // Skip system properties — they are always shown as-is.
                if (in_array($fieldName, $systemKeys, true)) {
                    continue;
                }

                $viewResult = $accessHandler->checkFieldAccess($entity, $fieldName, 'view', $account);
                if ($viewResult->isForbidden()) {
                    unset($properties[$fieldName]);
                    // Also remove from required list.
                    $required = array_values(array_filter(
                        $required,
                        static fn(string $name): bool => $name !== $fieldName,
                    ));
                    continue;
                }

                $editResult = $accessHandler->checkFieldAccess($entity, $fieldName, 'edit', $account);
                if ($editResult->isForbidden()) {
                    $properties[$fieldName]['readOnly'] = true;
                    $properties[$fieldName]['x-access-restricted'] = true;
                }
            }
        }
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php`

Expected: All 4 tests PASS.

**Step 5: Run existing SchemaPresenter tests for regressions**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Schema/`

Expected: All existing tests PASS (new params are optional).

**Step 6: Commit**

```
git add packages/api/src/Schema/SchemaPresenter.php packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php
git commit -m "feat(api): add field access metadata to SchemaPresenter"
```

---

### Task 6: Update admin frontend — useSchema.ts

**Files:**
- Modify: `packages/admin/app/composables/useSchema.ts:1-87`

**Step 1: Add `x-access-restricted` to the SchemaProperty interface**

In `packages/admin/app/composables/useSchema.ts`, add to the `SchemaProperty` interface (after line 18, the `x-target-type` line):

```ts
  'x-access-restricted'?: boolean
```

**Step 2: Update `sortedProperties()` to keep access-restricted fields in editable mode**

Replace the `sortedProperties` function (lines 70-84):

```ts
  /**
   * Return properties sorted by x-weight.
   *
   * When `editable` is true:
   *  - System readOnly fields (id, uuid — no x-access-restricted) are excluded.
   *  - Hidden widgets are excluded.
   *  - Access-restricted fields (readOnly + x-access-restricted) are kept — they
   *    render as disabled widgets so users can see but not edit the value.
   *
   * When false (default), all properties are returned.
   */
  function sortedProperties(editable = false) {
    if (!schema.value) return []

    const entries = Object.entries(schema.value.properties)

    const filtered = editable
      ? entries.filter(([, prop]) => {
          if (prop['x-widget'] === 'hidden') return false
          // System readOnly (no x-access-restricted) → exclude from form.
          if (prop.readOnly && !prop['x-access-restricted']) return false
          return true
        })
      : entries

    return filtered.sort(([, a], [, b]) => {
      const wa = a['x-weight'] ?? 0
      const wb = b['x-weight'] ?? 0
      return wa - wb
    })
  }
```

**Step 3: Commit**

```
git add packages/admin/app/composables/useSchema.ts
git commit -m "feat(admin): support x-access-restricted in useSchema"
```

---

### Task 7: Update admin frontend — SchemaField.vue and SchemaForm.vue

**Files:**
- Modify: `packages/admin/app/components/schema/SchemaField.vue:1-52`
- Modify: `packages/admin/app/components/schema/SchemaForm.vue:1-84`

**Step 1: Add disabled prop to SchemaField.vue**

Replace the full `SchemaField.vue`:

```vue
<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

const props = defineProps<{
  name: string
  modelValue: any
  schema: SchemaProperty
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: any] }>()

const label = computed(() => props.schema['x-label'] ?? props.name)
const description = computed(() => props.schema['x-description'] ?? props.schema.description)
const required = computed(() => props.schema['x-required'] ?? false)
const isDisabled = computed(() => props.disabled || (props.schema.readOnly && props.schema['x-access-restricted']))

const widgetMap: Record<string, Component> = {
  text: resolveComponent('WidgetsTextInput') as Component,
  email: resolveComponent('WidgetsTextInput') as Component,
  url: resolveComponent('WidgetsTextInput') as Component,
  textarea: resolveComponent('WidgetsTextArea') as Component,
  richtext: resolveComponent('WidgetsRichText') as Component,
  number: resolveComponent('WidgetsNumberInput') as Component,
  boolean: resolveComponent('WidgetsToggle') as Component,
  select: resolveComponent('WidgetsSelect') as Component,
  datetime: resolveComponent('WidgetsDateTimeInput') as Component,
  entity_autocomplete: resolveComponent('WidgetsEntityAutocomplete') as Component,
  hidden: resolveComponent('WidgetsHiddenField') as Component,
  password: resolveComponent('WidgetsTextInput') as Component,
  image: resolveComponent('WidgetsTextInput') as Component,
  file: resolveComponent('WidgetsTextInput') as Component,
}

const fallback = resolveComponent('WidgetsTextInput') as Component

const widgetComponent = computed(() => {
  const widget = props.schema['x-widget'] ?? 'text'
  return widgetMap[widget] ?? fallback
})
</script>

<template>
  <component
    :is="widgetComponent"
    :model-value="modelValue"
    :label="label"
    :description="description"
    :required="required"
    :disabled="isDisabled"
    :schema="schema"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>
```

**Step 2: Pass disabled prop from SchemaForm.vue**

In `packages/admin/app/components/schema/SchemaForm.vue`, update the `<SchemaField>` in the template (lines 62-69):

Replace:
```vue
      <SchemaField
        v-for="[fieldName, fieldSchema] in editableFields"
        :key="fieldName"
        :name="fieldName"
        :schema="fieldSchema"
        :model-value="formData[fieldName] ?? ''"
        @update:model-value="formData[fieldName] = $event"
      />
```
With:
```vue
      <SchemaField
        v-for="[fieldName, fieldSchema] in editableFields"
        :key="fieldName"
        :name="fieldName"
        :schema="fieldSchema"
        :disabled="!!fieldSchema['x-access-restricted']"
        :model-value="formData[fieldName] ?? ''"
        @update:model-value="!fieldSchema['x-access-restricted'] && (formData[fieldName] = $event)"
      />
```

**Step 3: Commit**

```
git add packages/admin/app/components/schema/SchemaField.vue packages/admin/app/components/schema/SchemaForm.vue
git commit -m "feat(admin): pass disabled prop for access-restricted fields"
```

---

### Task 8: Integration test — full round-trip

**Files:**
- Create: `tests/Integration/Phase6/FieldAccessIntegrationTest.php`

**Step 1: Write the integration test**

Create `tests/Integration/Phase6/FieldAccessIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase6;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
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
 * Integration test: full round-trip of field-level access control.
 *
 * Registers a field access policy, then verifies:
 * 1. JSON:API GET omits view-denied fields.
 * 2. Schema marks edit-denied fields as restricted.
 * 3. JSON:API PATCH rejects edit-denied field submission.
 * 4. JSON:API POST rejects edit-denied field submission.
 */
#[CoversNothing]
final class FieldAccessIntegrationTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;
    private AccountInterface $account;
    private JsonApiController $controller;
    private SchemaPresenter $schemaPresenter;
    private EntityType $entityType;

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

        // Policy: forbid viewing 'internal_notes', forbid editing 'status'.
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
                if ($fieldName === 'internal_notes' && $operation === 'view') {
                    return AccessResult::forbidden('Internal notes are hidden');
                }
                if ($fieldName === 'status' && $operation === 'edit') {
                    return AccessResult::forbidden('Only admins can change status');
                }
                return AccessResult::neutral();
            }
        };

        $this->accessHandler = new EntityAccessHandler([$policy]);
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $this->schemaPresenter = new SchemaPresenter();

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $this->account,
        );
    }

    private function createAndSaveEntity(array $values): TestEntity
    {
        $entity = $this->storage->create($values);
        $this->storage->save($entity);
        return $entity;
    }

    // ---------------------------------------------------------------
    // Round-trip: JSON:API GET omits view-denied fields
    // ---------------------------------------------------------------

    #[Test]
    public function jsonApiGetOmitsViewDeniedFields(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'My Article',
            'body' => 'Public content',
            'internal_notes' => 'For editors only',
            'status' => 'draft',
        ]);

        $doc = $this->controller->show('article', $entity->id());
        $array = $doc->toArray();

        // View-denied field is omitted.
        $this->assertArrayNotHasKey('internal_notes', $array['data']['attributes']);
        // Other fields present.
        $this->assertSame('Public content', $array['data']['attributes']['body']);
        $this->assertSame('draft', $array['data']['attributes']['status']);
    }

    // ---------------------------------------------------------------
    // Round-trip: Schema marks edit-denied fields as restricted
    // ---------------------------------------------------------------

    #[Test]
    public function schemaMarksEditDeniedAsRestricted(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('article');

        $fieldDefs = [
            'body' => ['type' => 'text', 'label' => 'Body'],
            'internal_notes' => ['type' => 'text', 'label' => 'Internal Notes'],
            'status' => ['type' => 'string', 'label' => 'Status'],
        ];

        $schema = $this->schemaPresenter->present(
            $this->entityType,
            $fieldDefs,
            $entity,
            $this->accessHandler,
            $this->account,
        );

        // View-denied field removed.
        $this->assertArrayNotHasKey('internal_notes', $schema['properties']);

        // Edit-denied field marked as restricted.
        $this->assertTrue($schema['properties']['status']['readOnly']);
        $this->assertTrue($schema['properties']['status']['x-access-restricted']);

        // Fully accessible field unchanged.
        $this->assertArrayNotHasKey('readOnly', $schema['properties']['body']);
    }

    // ---------------------------------------------------------------
    // Round-trip: PATCH rejects edit-denied field
    // ---------------------------------------------------------------

    #[Test]
    public function patchRejectsEditDeniedField(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'My Article', 'status' => 'draft',
        ]);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
        $this->assertStringContainsString('status', $doc->toArray()['errors'][0]['detail']);
    }

    // ---------------------------------------------------------------
    // Round-trip: POST rejects edit-denied field
    // ---------------------------------------------------------------

    #[Test]
    public function postRejectsEditDeniedField(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New', 'status' => 'published'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
        $this->assertStringContainsString('status', $doc->toArray()['errors'][0]['detail']);
    }

    // ---------------------------------------------------------------
    // Backward compat: no policies = no change
    // ---------------------------------------------------------------

    #[Test]
    public function noPoliciesAllowsAllFieldsByDefault(): void
    {
        $handlerNoPolicies = new EntityAccessHandler();
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $controller = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $handlerNoPolicies,
            $this->account,
        );

        $entity = $this->createAndSaveEntity([
            'title' => 'Article',
            'body' => 'Content',
            'internal_notes' => 'Private',
            'status' => 'draft',
        ]);

        // GET: all fields present.
        $doc = $controller->show('article', $entity->id());
        $attrs = $doc->toArray()['data']['attributes'];
        $this->assertArrayHasKey('body', $attrs);
        $this->assertArrayHasKey('internal_notes', $attrs);
        $this->assertArrayHasKey('status', $attrs);

        // PATCH: all fields editable.
        $doc = $controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);
        $this->assertSame(200, $doc->statusCode);
    }
}
```

**Step 2: Run the integration test**

Run: `./vendor/bin/phpunit tests/Integration/Phase6/FieldAccessIntegrationTest.php`

Expected: All 5 tests PASS.

**Step 3: Run the full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`

Expected: All tests PASS, no regressions.

**Step 4: Commit**

```
git add tests/Integration/Phase6/FieldAccessIntegrationTest.php
git commit -m "test: add field-level access integration tests (Phase 6)"
```

---

### Task 9: Final verification and PR

**Step 1: Run full test suite one more time**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`

Expected: All tests PASS.

**Step 2: Review all changes**

Run: `git log --oneline main..HEAD`

Expected commits:
```
feat(access): add FieldAccessPolicyInterface
feat(access): add checkFieldAccess() and filterFields() to EntityAccessHandler
feat(api): add field access filtering to ResourceSerializer
feat(api): add field edit access checks to JsonApiController
feat(api): add field access metadata to SchemaPresenter
feat(admin): support x-access-restricted in useSchema
feat(admin): pass disabled prop for access-restricted fields
test: add field-level access integration tests (Phase 6)
```

**Step 3: Create PR**

```
gh pr create --title "feat: field-level access control (#6)" --body "$(cat <<'EOF'
## Summary
- New `FieldAccessPolicyInterface` for field-level view/edit access checks
- `EntityAccessHandler` gains `checkFieldAccess()` and `filterFields()` methods
- `ResourceSerializer` omits view-denied fields from JSON:API responses
- `JsonApiController` rejects edit-denied fields on POST/PATCH with 403
- `SchemaPresenter` annotates edit-denied fields with `x-access-restricted`
- Admin SPA shows access-restricted fields as disabled widgets

## Design
See `docs/plans/2026-03-01-field-level-access-design.md`

## Test plan
- [ ] Unit tests: FieldAccessPolicyInterface contract
- [ ] Unit tests: EntityAccessHandler checkFieldAccess/filterFields
- [ ] Unit tests: ResourceSerializer field omission
- [ ] Unit tests: JsonApiController field edit rejection
- [ ] Unit tests: SchemaPresenter access metadata
- [ ] Integration test: full round-trip (Phase 6)
- [ ] Verify no regressions in existing test suite

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

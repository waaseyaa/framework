# GraphQL Integration Tests Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add integration tests exercising the full GraphQL query/mutation round-trip with real SQLite storage, access control, and entity references.

**Architecture:** Tests go through `GraphQlEndpoint::handle()` for full-stack coverage. Three test entity types (article, author, organization) form a reference graph with linear chain and circular self-reference. In-memory SQLite via `PdoDatabase::createSqlite()` provides real storage.

**Tech Stack:** PHPUnit 10, waaseyaa/graphql, waaseyaa/entity-storage, waaseyaa/access, waaseyaa/database-legacy (PdoDatabase), SQLite in-memory

**Spec:** `docs/history/superpowers/specs/2026-03-18-graphql-integration-tests-design.md`

---

### Task 1: Create Test Entity Classes

**Files:**
- Create: `tests/Integration/GraphQL/Entity/TestArticle.php`
- Create: `tests/Integration/GraphQL/Entity/TestAuthor.php`
- Create: `tests/Integration/GraphQL/Entity/TestOrganization.php`

- [ ] **Step 1: Create TestArticle entity**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class TestArticle extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'article',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
```

- [ ] **Step 2: Create TestAuthor entity**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class TestAuthor extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'author',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
```

- [ ] **Step 3: Create TestOrganization entity**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class TestOrganization extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'organization',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/GraphQL/Entity/
git commit -m "test: add entity classes for GraphQL integration tests (#431)"
```

---

### Task 2: Create Test Access Policies

**Files:**
- Create: `tests/Integration/GraphQL/Policy/AllowAllPolicy.php`
- Create: `tests/Integration/GraphQL/Policy/DenyByIdPolicy.php`
- Create: `tests/Integration/GraphQL/Policy/RestrictFieldPolicy.php`

**Important context:** `GraphQlAccessGuard::canView()` uses `isAllowed()`, not `!isForbidden()`. This means `AccessResult::neutral()` is effectively a deny for entity-level view. An `AllowAllPolicy` is needed as the baseline to grant access, then `DenyByIdPolicy` overrides specific entities with `forbidden()` (which wins in `orIf` logic).

- [ ] **Step 1: Create AllowAllPolicy**

Implements `AccessPolicyInterface`. Returns `AccessResult::allowed()` for all operations on all entity types. This provides the baseline "allow everything" that other policies override.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

final class AllowAllPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('Test: allow all');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('Test: allow all creates');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}
```

- [ ] **Step 2: Create DenyByIdPolicy**

Implements `AccessPolicyInterface`. Returns `AccessResult::forbidden()` when entity type, ID, and operation (`view`) match. All other cases return `AccessResult::neutral()`.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

final class DenyByIdPolicy implements AccessPolicyInterface
{
    public function __construct(
        private readonly string $entityTypeId,
        private readonly int|string $denyId,
    ) {}

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view'
            && $entity->getEntityTypeId() === $this->entityTypeId
            && $entity->id() === $this->denyId) {
            return AccessResult::forbidden("Test: entity {$this->denyId} denied");
        }

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
}
```

- [ ] **Step 3: Create RestrictFieldPolicy**

Implements both `AccessPolicyInterface` and `FieldAccessPolicyInterface`. Returns `AccessResult::forbidden()` for the configured field name. `EntityAccessHandler::checkFieldAccess()` only calls `fieldAccess()` on policies that also implement `FieldAccessPolicyInterface`.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

final class RestrictFieldPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    public function __construct(
        private readonly string $entityTypeId,
        private readonly string $fieldName,
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

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($fieldName === $this->fieldName) {
            return AccessResult::forbidden("Test: field {$this->fieldName} restricted");
        }

        return AccessResult::neutral();
    }
}
```

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/GraphQL/Policy/
git commit -m "test: add access policies for GraphQL integration tests (#431)"
```

---

### Task 3: Create Base Test Class

**Files:**
- Create: `tests/Integration/GraphQL/GraphQlIntegrationTestBase.php`

**Context:** This base class wires the full GraphQL stack with real SQLite storage. All test subclasses inherit this setUp and helper methods. The `storageFactory` closure on `EntityTypeManager` is key: it lets `getStorage()` return our pre-built `SqlEntityStorage` instances.

- [ ] **Step 1: Write the base test class**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\GraphQL\Schema\SchemaFactory;
use Waaseyaa\Tests\Integration\GraphQL\Entity\TestArticle;
use Waaseyaa\Tests\Integration\GraphQL\Entity\TestAuthor;
use Waaseyaa\Tests\Integration\GraphQL\Entity\TestOrganization;
use Waaseyaa\Tests\Integration\GraphQL\Policy\AllowAllPolicy;
use Waaseyaa\Tests\Integration\GraphQL\Policy\DenyByIdPolicy;
use Waaseyaa\Tests\Integration\GraphQL\Policy\RestrictFieldPolicy;

abstract class GraphQlIntegrationTestBase extends TestCase
{
    protected PdoDatabase $database;
    protected EntityTypeManager $entityTypeManager;
    protected GraphQlEndpoint $endpoint;
    protected EntityAccessHandler $accessHandler;

    /** @var array<string, SqlEntityStorage> */
    protected array $storages = [];

    protected function setUp(): void
    {
        SchemaFactory::resetCache();

        $this->database = PdoDatabase::createSqlite();
        $eventDispatcher = new EventDispatcher();

        // Define entity types with fieldDefinitions for GraphQL schema generation.
        $articleType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestArticle::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string', 'required' => true],
                'body' => ['type' => 'text'],
                'author_id' => ['type' => 'entity_reference', 'target' => 'author'],
                'related_article_id' => ['type' => 'entity_reference', 'target' => 'article'],
            ],
        );

        $authorType = new EntityType(
            id: 'author',
            label: 'Author',
            class: TestAuthor::class,
            keys: ['id' => 'id', 'label' => 'name'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'required' => true],
                'bio' => ['type' => 'text'],
                'secret' => ['type' => 'string'],
                'organization_id' => ['type' => 'entity_reference', 'target' => 'organization'],
            ],
        );

        $organizationType = new EntityType(
            id: 'organization',
            label: 'Organization',
            class: TestOrganization::class,
            keys: ['id' => 'id', 'label' => 'name'],
            fieldDefinitions: [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'required' => true],
                'location' => ['type' => 'string'],
            ],
        );

        // Create storage instances first, then wire a factory that returns them.
        $types = ['article' => $articleType, 'author' => $authorType, 'organization' => $organizationType];

        foreach ($types as $id => $type) {
            $schemaHandler = new SqlSchemaHandler($type, $this->database);
            $schemaHandler->ensureTable();
            $this->storages[$id] = new SqlEntityStorage($type, $this->database, $eventDispatcher);
        }

        // EntityTypeManager with storageFactory closure that returns pre-built storages.
        $storages = $this->storages;
        $this->entityTypeManager = new EntityTypeManager(
            $eventDispatcher,
            static fn(EntityTypeInterface $type) => $storages[$type->id()],
        );

        foreach ($types as $type) {
            $this->entityTypeManager->registerEntityType($type);
        }

        // Seed test data.
        $this->seedData();

        // Access policies: AllowAll baseline, DenyById for article 2, RestrictField for author.secret.
        $this->accessHandler = new EntityAccessHandler([
            new AllowAllPolicy(),
            new DenyByIdPolicy('article', 2),
            new RestrictFieldPolicy('author', 'secret'),
        ]);

        // Wire GraphQlEndpoint with authenticated test account.
        $this->endpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $this->accessHandler,
            $this->createAccount(1),
        );
    }

    protected function seedData(): void
    {
        // Organization.
        $org = $this->storages['organization']->create(['name' => 'Acme', 'location' => 'NYC']);
        $this->storages['organization']->save($org);

        // Authors.
        $alice = $this->storages['author']->create([
            'name' => 'Alice', 'bio' => 'Writer', 'secret' => 'classified', 'organization_id' => 1,
        ]);
        $this->storages['author']->save($alice);

        $bob = $this->storages['author']->create([
            'name' => 'Bob', 'bio' => 'Editor', 'secret' => 'redacted', 'organization_id' => 1,
        ]);
        $this->storages['author']->save($bob);

        // Articles (circular: article1 -> article2, article2 -> article1).
        $article1 = $this->storages['article']->create([
            'title' => 'Hello', 'body' => 'Content 1', 'author_id' => 1, 'related_article_id' => 2,
        ]);
        $this->storages['article']->save($article1);

        $article2 = $this->storages['article']->create([
            'title' => 'World', 'body' => 'Content 2', 'author_id' => 2, 'related_article_id' => 1,
        ]);
        $this->storages['article']->save($article2);
    }

    /**
     * Execute a GraphQL query through the endpoint.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed> Decoded response body.
     */
    protected function query(string $graphql, array $variables = []): array
    {
        $body = json_encode(['query' => $graphql, 'variables' => $variables], JSON_THROW_ON_ERROR);
        $result = $this->endpoint->handle('POST', $body);

        return $result['body'];
    }

    protected function assertNoErrors(array $response): void
    {
        $this->assertArrayNotHasKey('errors', $response, sprintf(
            'GraphQL response contained errors: %s',
            isset($response['errors']) ? json_encode($response['errors'], JSON_PRETTY_PRINT) : 'none',
        ));
    }

    protected function assertHasError(array $response, string $messageFragment): void
    {
        $this->assertArrayHasKey('errors', $response, 'Expected GraphQL errors but none found');
        $messages = array_map(
            static fn(array $error): string => $error['message'] ?? '',
            $response['errors'],
        );
        $found = false;
        foreach ($messages as $message) {
            if (str_contains($message, $messageFragment)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, sprintf(
            'Expected error containing "%s" but got: %s',
            $messageFragment,
            implode(', ', $messages),
        ));
    }

    protected function createAccount(int|string $id, array $roles = ['authenticated'], array $permissions = []): AccountInterface
    {
        return new class ($id, $roles, $permissions) implements AccountInterface {
            /** @param string[] $roles @param string[] $permissions */
            public function __construct(
                private readonly int|string $id,
                private readonly array $roles,
                private readonly array $permissions,
            ) {}

            public function id(): int|string
            {
                return $this->id;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return $this->id !== 0;
            }
        };
    }
}
```

- [ ] **Step 2: Verify file compiles**

Run: `php -l tests/Integration/GraphQL/GraphQlIntegrationTestBase.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/GraphQL/GraphQlIntegrationTestBase.php
git commit -m "test: add base class for GraphQL integration tests (#431)"
```

---

### Task 4: CRUD Tests (Tests 1-4)

**Files:**
- Create: `tests/Integration/GraphQL/GraphQlCrudTest.php`

- [ ] **Step 1: Write the CRUD test class**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

/**
 * Tests GraphQL CRUD operations with real SQLite storage.
 */
final class GraphQlCrudTest extends GraphQlIntegrationTestBase
{
    public function testListReturnsPersistentEntities(): void
    {
        $response = $this->query('{ articleList { items { title } total } }');

        $this->assertNoErrors($response);
        $data = $response['data']['articleList'];

        // total=2 (count query runs without access filtering).
        $this->assertSame(2, $data['total']);

        // items contains only article1 (article2 denied by DenyByIdPolicy).
        $titles = array_column($data['items'], 'title');
        $this->assertContains('Hello', $titles);
        $this->assertNotContains('World', $titles);
    }

    public function testCreateMutationPersistsAndReturns(): void
    {
        $response = $this->query('
            mutation {
                createArticle(input: { title: "New Article" }) {
                    id
                    title
                }
            }
        ');

        $this->assertNoErrors($response);
        $created = $response['data']['createArticle'];
        $this->assertSame('New Article', $created['title']);
        $this->assertNotEmpty($created['id']);

        // Verify it persists: query it back.
        $id = $created['id'];
        $verify = $this->query("{ article(id: \"{$id}\") { title } }");
        $this->assertNoErrors($verify);
        $this->assertSame('New Article', $verify['data']['article']['title']);
    }

    public function testUpdateMutationModifiesEntity(): void
    {
        $response = $this->query('
            mutation {
                updateArticle(id: "1", input: { title: "Updated" }) {
                    title
                }
            }
        ');

        $this->assertNoErrors($response);
        $this->assertSame('Updated', $response['data']['updateArticle']['title']);

        // Verify persistence.
        $verify = $this->query('{ article(id: "1") { title } }');
        $this->assertNoErrors($verify);
        $this->assertSame('Updated', $verify['data']['article']['title']);
    }

    public function testDeleteMutationRemovesEntity(): void
    {
        // Create a temporary entity to delete (don't delete seeded data).
        $create = $this->query('
            mutation {
                createArticle(input: { title: "Temp" }) { id }
            }
        ');
        $id = $create['data']['createArticle']['id'];

        $response = $this->query("
            mutation {
                deleteArticle(id: \"{$id}\") {
                    deleted
                }
            }
        ");

        $this->assertNoErrors($response);
        $this->assertTrue($response['data']['deleteArticle']['deleted']);

        // Verify it's gone.
        $verify = $this->query("{ article(id: \"{$id}\") { title } }");
        $this->assertNoErrors($verify);
        $this->assertNull($verify['data']['article']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail (storage not yet exercised through endpoint)**

Run: `cd /home/fsd42/dev/waaseyaa && vendor/bin/phpunit tests/Integration/GraphQL/GraphQlCrudTest.php --testdox`
Expected: Tests run. Pass or fail, this validates the wiring compiles and runs.

- [ ] **Step 3: Fix any wiring issues discovered**

If tests fail due to wiring issues (missing columns, wrong field names, schema generation errors), fix the base class setUp to match the actual framework API.

- [ ] **Step 4: Run tests again to verify they pass**

Run: `cd /home/fsd42/dev/waaseyaa && vendor/bin/phpunit tests/Integration/GraphQL/GraphQlCrudTest.php --testdox`
Expected: 4 tests, 4 assertions, all PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/GraphQL/GraphQlCrudTest.php
git commit -m "test: add GraphQL CRUD integration tests (#431)"
```

---

### Task 5: Access Control Tests (Tests 5-6)

**Files:**
- Create: `tests/Integration/GraphQL/GraphQlAccessTest.php`

- [ ] **Step 1: Write the access test class**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

/**
 * Tests GraphQL access control with real storage and policies.
 */
final class GraphQlAccessTest extends GraphQlIntegrationTestBase
{
    public function testAccessDeniedEntitiesExcludedFromList(): void
    {
        $response = $this->query('{ articleList { items { id title } total } }');

        $this->assertNoErrors($response);
        $data = $response['data']['articleList'];

        // total=2 (count query is unfiltered).
        $this->assertSame(2, $data['total']);

        // article2 (id=2) should be absent from items (silently filtered).
        $ids = array_column($data['items'], 'id');
        $this->assertNotContains('2', $ids);
        $this->assertNotContains(2, $ids);
        $this->assertCount(1, $data['items']);
    }

    public function testFieldLevelAccessFiltersRestrictedFields(): void
    {
        $response = $this->query('{ author(id: "1") { name secret } }');

        $this->assertNoErrors($response);
        $author = $response['data']['author'];

        // name should be present.
        $this->assertSame('Alice', $author['name']);

        // secret should be null (filtered by RestrictFieldPolicy).
        $this->assertNull($author['secret']);
    }
}
```

- [ ] **Step 2: Run tests**

Run: `cd /home/fsd42/dev/waaseyaa && vendor/bin/phpunit tests/Integration/GraphQL/GraphQlAccessTest.php --testdox`
Expected: 2 tests, all PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/GraphQL/GraphQlAccessTest.php
git commit -m "test: add GraphQL access control integration tests (#431)"
```

---

### Task 6: Reference Resolution Tests (Tests 7-9)

**Files:**
- Create: `tests/Integration/GraphQL/GraphQlReferenceTest.php`

- [ ] **Step 1: Write the reference test class**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

/**
 * Tests entity reference resolution, multi-hop chains, and depth limiting.
 */
final class GraphQlReferenceTest extends GraphQlIntegrationTestBase
{
    public function testReferenceResolvesNestedEntity(): void
    {
        $response = $this->query('
            { article(id: "1") { title author { name } } }
        ');

        $this->assertNoErrors($response);
        $article = $response['data']['article'];
        $this->assertSame('Hello', $article['title']);
        $this->assertSame('Alice', $article['author']['name']);
    }

    public function testMultiHopReferenceResolves(): void
    {
        $response = $this->query('
            { article(id: "1") { author { organization { name } } } }
        ');

        $this->assertNoErrors($response);
        $org = $response['data']['article']['author']['organization'];
        $this->assertSame('Acme', $org['name']);
    }

    public function testCircularReferenceRespectsDepthLimit(): void
    {
        // Default maxDepth=3. Three hops of relatedArticle should hit the limit.
        $response = $this->query('
            {
                article(id: "1") {
                    relatedArticle {
                        relatedArticle {
                            relatedArticle {
                                title
                            }
                        }
                    }
                }
            }
        ');

        // The response should not error, but the deepest level should be null.
        $article = $response['data']['article'];
        $this->assertNotNull($article['relatedArticle'], 'Depth 1 should resolve');

        // At depth 3 (the third nesting), the reference should be null.
        $depth2 = $article['relatedArticle']['relatedArticle'] ?? null;
        $depth3 = $depth2['relatedArticle'] ?? null;
        $this->assertNull($depth3, 'Depth 3 should return null (maxDepth exceeded)');
    }
}
```

- [ ] **Step 2: Run tests**

Run: `cd /home/fsd42/dev/waaseyaa && vendor/bin/phpunit tests/Integration/GraphQL/GraphQlReferenceTest.php --testdox`
Expected: 3 tests, all PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/GraphQL/GraphQlReferenceTest.php
git commit -m "test: add GraphQL reference resolution integration tests (#431)"
```

---

### Task 7: Role-Based Access Test (Test 10)

**Files:**
- Create: `tests/Integration/GraphQL/GraphQlRoleBasedAccessTest.php`

**Context:** This test creates three separate `GraphQlEndpoint` instances with different accounts. It uses a role-aware policy instead of the simple deny-by-ID policies. The `AllowAllPolicy` is NOT used here; instead, a custom policy grants access based on roles.

- [ ] **Step 1: Create a RoleBasedPolicy for this test**

Add `tests/Integration/GraphQL/Policy/RoleBasedPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

final class RoleBasedPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (in_array('admin', $account->getRoles(), true)) {
            return AccessResult::allowed('Admin access');
        }

        if (!$account->isAuthenticated()) {
            return AccessResult::forbidden('Anonymous denied');
        }

        // Members can view but not update/delete.
        if ($operation === 'view') {
            return AccessResult::allowed('Member view access');
        }

        return AccessResult::forbidden('Members cannot modify');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (in_array('admin', $account->getRoles(), true)) {
            return AccessResult::allowed('Admin create');
        }

        return AccessResult::forbidden('Non-admin cannot create');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        // Members cannot see 'secret' field.
        if ($fieldName === 'secret' && !in_array('admin', $account->getRoles(), true)) {
            return AccessResult::forbidden('Secret restricted to admins');
        }

        return AccessResult::neutral();
    }
}
```

- [ ] **Step 2: Write the role-based access test class**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\GraphQL\GraphQlEndpoint;
use Waaseyaa\Tests\Integration\GraphQL\Policy\RoleBasedPolicy;

/**
 * Tests realistic role-based access patterns through GraphQL.
 */
final class GraphQlRoleBasedAccessTest extends GraphQlIntegrationTestBase
{
    private GraphQlEndpoint $adminEndpoint;
    private GraphQlEndpoint $anonymousEndpoint;
    private GraphQlEndpoint $memberEndpoint;

    protected function setUp(): void
    {
        parent::setUp();

        // Replace the default policies with role-based policy.
        $roleHandler = new EntityAccessHandler([new RoleBasedPolicy()]);

        $this->adminEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $roleHandler,
            $this->createAccount(1, ['admin', 'authenticated']),
        );

        $this->anonymousEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $roleHandler,
            $this->createAccount(0, ['anonymous']),
        );

        $this->memberEndpoint = new GraphQlEndpoint(
            $this->entityTypeManager,
            $roleHandler,
            $this->createAccount(2, ['authenticated', 'member']),
        );
    }

    private function queryAs(GraphQlEndpoint $endpoint, string $graphql): array
    {
        $body = json_encode(['query' => $graphql], JSON_THROW_ON_ERROR);
        return $endpoint->handle('POST', $body)['body'];
    }

    public function testAdminSeesAllEntitiesAndFields(): void
    {
        $response = $this->queryAs($this->adminEndpoint, '
            { articleList { items { title } total } }
        ');

        $this->assertNoErrors($response);
        $this->assertSame(2, $response['data']['articleList']['total']);
        $this->assertCount(2, $response['data']['articleList']['items']);

        // Admin can see secret field.
        $author = $this->queryAs($this->adminEndpoint, '{ author(id: "1") { name secret } }');
        $this->assertNoErrors($author);
        $this->assertSame('classified', $author['data']['author']['secret']);
    }

    public function testAnonymousSeesNothing(): void
    {
        $response = $this->queryAs($this->anonymousEndpoint, '
            { articleList { items { title } total } }
        ');

        $this->assertNoErrors($response);

        // Anonymous should see no items (all denied by RoleBasedPolicy).
        $items = $response['data']['articleList']['items'] ?? [];
        $this->assertCount(0, $items);
    }

    public function testMemberSeesFilteredResults(): void
    {
        $response = $this->queryAs($this->memberEndpoint, '
            { articleList { items { title } total } }
        ');

        $this->assertNoErrors($response);
        // Member can view all entities.
        $this->assertCount(2, $response['data']['articleList']['items']);

        // Member cannot see secret field.
        $author = $this->queryAs($this->memberEndpoint, '{ author(id: "1") { name secret } }');
        $this->assertNoErrors($author);
        $this->assertNull($author['data']['author']['secret']);
    }
}
```

- [ ] **Step 3: Run tests**

Run: `cd /home/fsd42/dev/waaseyaa && vendor/bin/phpunit tests/Integration/GraphQL/GraphQlRoleBasedAccessTest.php --testdox`
Expected: 3 tests, all PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/GraphQL/Policy/RoleBasedPolicy.php tests/Integration/GraphQL/GraphQlRoleBasedAccessTest.php
git commit -m "test: add role-based access integration test (#431)"
```

---

### Task 8: Run Full Suite & Final Commit

**Files:**
- No new files

- [ ] **Step 1: Run all GraphQL integration tests**

Run: `cd /home/fsd42/dev/waaseyaa && vendor/bin/phpunit tests/Integration/GraphQL/ --testdox`
Expected: 12 tests, all PASS

- [ ] **Step 2: Run the full framework test suite**

Run: `cd /home/fsd42/dev/waaseyaa && vendor/bin/phpunit --testsuite Integration`
Expected: All integration tests pass (no regressions)

- [ ] **Step 3: Run PHPStan**

Run: `cd /home/fsd42/dev/waaseyaa && vendor/bin/phpstan analyse tests/Integration/GraphQL/ --level 5`
Expected: No errors

- [ ] **Step 4: Fix any issues found in steps 1-3**

If any tests fail or PHPStan reports errors, fix them.

- [ ] **Step 5: Final commit if fixes were needed**

```bash
git add tests/Integration/GraphQL/
git commit -m "test: fix GraphQL integration test issues (#431)"
```

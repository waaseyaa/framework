# Content Authoring MVP Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make content authoring actually work end-to-end by fixing the two blockers: (1) no authenticated user → all mutations 403, and (2) schema only exposes entity keys → forms show only "Title".

**Architecture:** Add `DevAdminAccount` class implementing `AccountInterface` with all permissions. Wire it into `SessionMiddleware` as a dev fallback when `PHP_SAPI === 'cli-server'`. Add `getFieldDefinitions()` to `EntityTypeInterface` with a default `[]` return in `EntityType`. Override in entity type registrations via a new `fieldDefinitions` constructor param. `SchemaController` calls `$entityType->getFieldDefinitions()` and passes to `SchemaPresenter`.

**Tech Stack:** PHP 8.3, PHPUnit 10.5, Nuxt 3 (no frontend changes needed — schema-driven forms adapt automatically)

---

### Task 1: DevAdminAccount — create the dev admin class

**Files:**
- Create: `packages/user/src/DevAdminAccount.php`
- Test: `packages/user/tests/Unit/DevAdminAccountTest.php`

**Step 1: Write the failing test**

Create `packages/user/tests/Unit/DevAdminAccountTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\User\DevAdminAccount;

#[CoversClass(DevAdminAccount::class)]
final class DevAdminAccountTest extends TestCase
{
    #[Test]
    public function implements_account_interface(): void
    {
        $account = new DevAdminAccount();
        $this->assertInstanceOf(AccountInterface::class, $account);
    }

    #[Test]
    public function id_returns_one(): void
    {
        $account = new DevAdminAccount();
        $this->assertSame(1, $account->id());
    }

    #[Test]
    public function has_permission_always_returns_true(): void
    {
        $account = new DevAdminAccount();
        $this->assertTrue($account->hasPermission('administer nodes'));
        $this->assertTrue($account->hasPermission('access content'));
        $this->assertTrue($account->hasPermission('any random permission'));
    }

    #[Test]
    public function get_roles_returns_administrator(): void
    {
        $account = new DevAdminAccount();
        $this->assertSame(['administrator'], $account->getRoles());
    }

    #[Test]
    public function is_authenticated_returns_true(): void
    {
        $account = new DevAdminAccount();
        $this->assertTrue($account->isAuthenticated());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/DevAdminAccountTest.php`
Expected: FAIL — class `DevAdminAccount` does not exist.

**Step 3: Write the implementation**

Create `packages/user/src/DevAdminAccount.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccountInterface;

/**
 * Dev-only admin account with all permissions.
 *
 * Used as a fallback when running under PHP's built-in server (cli-server)
 * and no session is active. MUST NOT be used in production.
 */
final class DevAdminAccount implements AccountInterface
{
    public function id(): int
    {
        return 1;
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return ['administrator'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/DevAdminAccountTest.php`
Expected: PASS (5 tests, 7 assertions)

**Step 5: Commit**

```bash
git add packages/user/src/DevAdminAccount.php packages/user/tests/Unit/DevAdminAccountTest.php
git commit -m "feat(user): add DevAdminAccount for dev-mode admin bypass"
```

---

### Task 2: SessionMiddleware — add dev fallback parameter

**Files:**
- Modify: `packages/user/src/Middleware/SessionMiddleware.php`
- Modify: `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`

**Step 1: Write the failing test**

Add to `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`:

```php
#[Test]
public function uses_dev_fallback_when_no_session_and_fallback_provided(): void
{
    $devAccount = new \Waaseyaa\User\DevAdminAccount();
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->never())->method('load');

    $middleware = new SessionMiddleware($storage, $devAccount);
    $request = Request::create('/test');

    $capturedAccount = null;
    $next = new class($capturedAccount) implements HttpHandlerInterface {
        public function __construct(private ?AccountInterface &$ref) {}

        public function handle(Request $request): Response
        {
            $this->ref = $request->attributes->get('_account');
            return new Response('ok');
        }
    };

    $middleware->process($request, $next);

    $this->assertInstanceOf(\Waaseyaa\User\DevAdminAccount::class, $capturedAccount);
    $this->assertSame(1, $capturedAccount->id());
}

#[Test]
public function ignores_dev_fallback_when_session_exists(): void
{
    $devAccount = new \Waaseyaa\User\DevAdminAccount();
    $user = new User(['uid' => 42, 'name' => 'admin', 'permissions' => ['access content']]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
        ->method('load')
        ->with(42)
        ->willReturn($user);

    $middleware = new SessionMiddleware($storage, $devAccount);
    $request = Request::create('/test');
    $request->attributes->set('_session', ['waaseyaa_uid' => 42]);

    $capturedAccount = null;
    $next = new class($capturedAccount) implements HttpHandlerInterface {
        public function __construct(private ?AccountInterface &$ref) {}

        public function handle(Request $request): Response
        {
            $this->ref = $request->attributes->get('_account');
            return new Response('ok');
        }
    };

    $middleware->process($request, $next);

    $this->assertInstanceOf(User::class, $capturedAccount);
    $this->assertSame(42, $capturedAccount->id());
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php --filter "dev_fallback"`
Expected: FAIL — constructor signature mismatch.

**Step 3: Update SessionMiddleware**

In `packages/user/src/Middleware/SessionMiddleware.php`, change the constructor and `resolveAccount`:

```php
public function __construct(
    private readonly EntityStorageInterface $userStorage,
    private readonly ?AccountInterface $devFallback = null,
) {}
```

And in `resolveAccount`, change the early return from `return new AnonymousUser();` to:

```php
if ($uid === null) {
    return $this->devFallback ?? new AnonymousUser();
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`
Expected: PASS (7 tests, 7 assertions)

**Step 5: Commit**

```bash
git add packages/user/src/Middleware/SessionMiddleware.php packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php
git commit -m "feat(user): add dev fallback account to SessionMiddleware"
```

---

### Task 3: Wire DevAdminAccount in index.php

**Files:**
- Modify: `public/index.php`

**Step 1: Add the use statement**

At the top of `public/index.php` where the other `use` statements are, add:

```php
use Waaseyaa\User\DevAdminAccount;
```

**Step 2: Update SessionMiddleware instantiation**

Find the line (around line 355):

```php
->withMiddleware(new SessionMiddleware($userStorage))
```

Replace with:

```php
->withMiddleware(new SessionMiddleware(
    $userStorage,
    PHP_SAPI === 'cli-server' ? new DevAdminAccount() : null,
))
```

**Step 3: Verify existing tests still pass**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: PASS (all tests)

**Step 4: Manual smoke test**

Run: `curl -s http://localhost:8081/api/node -X POST -H 'Content-Type: application/vnd.api+json' -d '{"data":{"type":"node","attributes":{"title":"Test","type":"article"}}}' | python3 -m json.tool`
Expected: 201 with created node resource (not 403).

**Step 5: Commit**

```bash
git add public/index.php
git commit -m "feat: wire DevAdminAccount for cli-server dev mode"
```

---

### Task 4: EntityTypeInterface — add getFieldDefinitions()

**Files:**
- Modify: `packages/entity/src/EntityTypeInterface.php`
- Modify: `packages/entity/src/EntityType.php`
- Modify: `packages/entity/tests/Unit/EntityTypeTest.php`

**Step 1: Write the failing test**

Add to `packages/entity/tests/Unit/EntityTypeTest.php`:

```php
public function testFieldDefinitionsDefaultsToEmptyArray(): void
{
    $type = new EntityType(
        id: 'test',
        label: 'Test',
        class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
    );

    $this->assertSame([], $type->getFieldDefinitions());
}

public function testFieldDefinitionsWithValues(): void
{
    $fields = [
        'status' => [
            'type' => 'boolean',
            'label' => 'Published',
            'weight' => 10,
        ],
        'uid' => [
            'type' => 'entity_reference',
            'label' => 'Author',
            'settings' => ['target_type' => 'user'],
            'weight' => 20,
        ],
    ];

    $type = new EntityType(
        id: 'node',
        label: 'Content',
        class: 'Waaseyaa\\Entity\\Tests\\Unit\\TestEntity',
        fieldDefinitions: $fields,
    );

    $this->assertSame($fields, $type->getFieldDefinitions());
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/entity/tests/Unit/EntityTypeTest.php --filter "FieldDefinitions"`
Expected: FAIL — method `getFieldDefinitions` does not exist.

**Step 3: Add to interface**

In `packages/entity/src/EntityTypeInterface.php`, add:

```php
/** @return array<string, array<string, mixed>> Field definitions keyed by field name. */
public function getFieldDefinitions(): array;
```

**Step 4: Add to EntityType class**

In `packages/entity/src/EntityType.php`, add `private array $fieldDefinitions = []` to the constructor parameters and implement the method:

Constructor — add parameter after `constraints`:

```php
private array $fieldDefinitions = [],
```

Method:

```php
/** @return array<string, array<string, mixed>> */
public function getFieldDefinitions(): array
{
    return $this->fieldDefinitions;
}
```

**Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/entity/tests/Unit/EntityTypeTest.php`
Expected: PASS

**Step 6: Run full suite to verify no breakage**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: PASS (all tests — default `[]` means all existing EntityType usages still work)

**Step 7: Commit**

```bash
git add packages/entity/src/EntityTypeInterface.php packages/entity/src/EntityType.php packages/entity/tests/Unit/EntityTypeTest.php
git commit -m "feat(entity): add getFieldDefinitions() to EntityTypeInterface"
```

---

### Task 5: SchemaController — pass field definitions to SchemaPresenter

**Files:**
- Modify: `packages/api/src/Controller/SchemaController.php`
- Modify: `packages/api/tests/Unit/Controller/SchemaControllerTest.php`

**Step 1: Write the failing test**

Add to `packages/api/tests/Unit/Controller/SchemaControllerTest.php`:

```php
#[Test]
public function showIncludesFieldDefinitionsInSchema(): void
{
    $storage = new InMemoryEntityStorage('node');
    $manager = new EntityTypeManager(new EventDispatcher(), fn() => $storage);

    $manager->registerEntityType(new EntityType(
        id: 'node',
        label: 'Content',
        class: TestEntity::class,
        keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        fieldDefinitions: [
            'status' => [
                'type' => 'boolean',
                'label' => 'Published',
                'weight' => 10,
            ],
            'uid' => [
                'type' => 'entity_reference',
                'label' => 'Author',
                'settings' => ['target_type' => 'user'],
                'weight' => 20,
            ],
        ],
    ));

    $controller = new SchemaController($manager, new SchemaPresenter());
    $doc = $controller->show('node');
    $schema = $doc->toArray()['meta']['schema'];

    $this->assertSame(200, $doc->statusCode);
    $this->assertArrayHasKey('status', $schema['properties']);
    $this->assertSame('boolean', $schema['properties']['status']['type']);
    $this->assertSame('boolean', $schema['properties']['status']['x-widget']);
    $this->assertSame('Published', $schema['properties']['status']['x-label']);

    $this->assertArrayHasKey('uid', $schema['properties']);
    $this->assertSame('entity_autocomplete', $schema['properties']['uid']['x-widget']);
    $this->assertSame('user', $schema['properties']['uid']['x-target-type']);
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Controller/SchemaControllerTest.php --filter "FieldDefinitions"`
Expected: FAIL — `status` and `uid` not in schema properties (because SchemaController passes `[]`).

**Step 3: Update SchemaController**

In `packages/api/src/Controller/SchemaController.php`, change line 60 from:

```php
$entityType,
[],
```

to:

```php
$entityType,
$entityType->getFieldDefinitions(),
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/api/tests/Unit/Controller/SchemaControllerTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add packages/api/src/Controller/SchemaController.php packages/api/tests/Unit/Controller/SchemaControllerTest.php
git commit -m "feat(api): pass entity field definitions to SchemaPresenter"
```

---

### Task 6: Register field definitions for Node

**Files:**
- Modify: `public/index.php`

**Step 1: Update node entity type registration**

Find the node EntityType registration (around line 128):

```php
new EntityType(
    id: 'node',
    label: 'Content',
    class: Node::class,
    keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
),
```

Replace with:

```php
new EntityType(
    id: 'node',
    label: 'Content',
    class: Node::class,
    keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
    fieldDefinitions: [
        'status' => [
            'type' => 'boolean',
            'label' => 'Published',
            'description' => 'Whether the content is published.',
            'weight' => 10,
        ],
        'promote' => [
            'type' => 'boolean',
            'label' => 'Promoted to front page',
            'description' => 'Whether the content is promoted to the front page.',
            'weight' => 11,
        ],
        'sticky' => [
            'type' => 'boolean',
            'label' => 'Sticky at top of lists',
            'description' => 'Whether the content is sticky at the top of lists.',
            'weight' => 12,
        ],
        'uid' => [
            'type' => 'entity_reference',
            'label' => 'Author',
            'description' => 'The user who authored this content.',
            'settings' => ['target_type' => 'user'],
            'weight' => 20,
        ],
        'created' => [
            'type' => 'timestamp',
            'label' => 'Authored on',
            'description' => 'The date and time the content was created.',
            'weight' => 30,
        ],
        'changed' => [
            'type' => 'timestamp',
            'label' => 'Last updated',
            'description' => 'The date and time the content was last updated.',
            'weight' => 31,
        ],
    ],
),
```

**Step 2: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: PASS

**Step 3: Manual smoke test**

Run: `curl -s http://localhost:8081/api/schema/node | python3 -m json.tool`
Expected: Schema now includes `status`, `promote`, `sticky`, `uid`, `created`, `changed` properties alongside the system properties.

**Step 4: Commit**

```bash
git add public/index.php
git commit -m "feat: register field definitions for node entity type"
```

---

### Task 7: Register field definitions for Taxonomy and User

**Files:**
- Modify: `public/index.php`

**Step 1: Update taxonomy_term registration**

Find the taxonomy_term EntityType (around line 140):

```php
new EntityType(
    id: 'taxonomy_term',
    label: 'Taxonomy Term',
    class: Term::class,
    keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'vid'],
),
```

Replace with:

```php
new EntityType(
    id: 'taxonomy_term',
    label: 'Taxonomy Term',
    class: Term::class,
    keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'vid'],
    fieldDefinitions: [
        'description' => [
            'type' => 'text',
            'label' => 'Description',
            'description' => 'A description of the term.',
            'weight' => 5,
        ],
        'weight' => [
            'type' => 'integer',
            'label' => 'Weight',
            'description' => 'The weight of this term for ordering.',
            'weight' => 10,
        ],
        'parent_id' => [
            'type' => 'entity_reference',
            'label' => 'Parent term',
            'description' => 'The parent term for hierarchical vocabularies.',
            'settings' => ['target_type' => 'taxonomy_term'],
            'weight' => 15,
        ],
        'status' => [
            'type' => 'boolean',
            'label' => 'Published',
            'description' => 'Whether the term is published.',
            'weight' => 20,
        ],
    ],
),
```

**Step 2: Update taxonomy_vocabulary registration**

Find the taxonomy_vocabulary EntityType (around line 146):

```php
new EntityType(
    id: 'taxonomy_vocabulary',
    label: 'Vocabulary',
    class: Vocabulary::class,
    keys: ['id' => 'vid', 'label' => 'name'],
),
```

Replace with:

```php
new EntityType(
    id: 'taxonomy_vocabulary',
    label: 'Vocabulary',
    class: Vocabulary::class,
    keys: ['id' => 'vid', 'label' => 'name'],
    fieldDefinitions: [
        'description' => [
            'type' => 'text',
            'label' => 'Description',
            'description' => 'A description of the vocabulary.',
            'weight' => 5,
        ],
        'weight' => [
            'type' => 'integer',
            'label' => 'Weight',
            'description' => 'The weight of this vocabulary for ordering.',
            'weight' => 10,
        ],
    ],
),
```

**Step 3: Update user registration**

Find the user EntityType (around line 120):

```php
new EntityType(
    id: 'user',
    label: 'User',
    class: User::class,
    keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
),
```

Replace with:

```php
new EntityType(
    id: 'user',
    label: 'User',
    class: User::class,
    keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
    fieldDefinitions: [
        'mail' => [
            'type' => 'email',
            'label' => 'Email address',
            'description' => 'The email address of the user.',
            'weight' => 5,
        ],
        'status' => [
            'type' => 'boolean',
            'label' => 'Active',
            'description' => 'Whether the user account is active.',
            'weight' => 10,
        ],
        'created' => [
            'type' => 'timestamp',
            'label' => 'Member since',
            'description' => 'The date the user account was created.',
            'weight' => 20,
        ],
    ],
),
```

**Step 4: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: PASS

**Step 5: Manual smoke test**

Run all three schema endpoints and verify fields appear:

```bash
curl -s http://localhost:8081/api/schema/taxonomy_term | python3 -m json.tool
curl -s http://localhost:8081/api/schema/taxonomy_vocabulary | python3 -m json.tool
curl -s http://localhost:8081/api/schema/user | python3 -m json.tool
```

Expected: Each includes their declared field definitions as schema properties.

**Step 6: Commit**

```bash
git add public/index.php
git commit -m "feat: register field definitions for taxonomy and user entity types"
```

---

### Task 8: End-to-end smoke test via browser

**Files:** None (manual verification)

**Step 1: Test node create flow in admin SPA**

1. Navigate to `http://localhost:3001/node/create`
2. Verify the form shows: Title, Published, Promoted to front page, Sticky at top of lists, Author, Authored on, Last updated
3. Fill in Title: "Test Article", set Published toggle on, set type to "article" (Note: type is hidden — verify what happens)
4. Click Create
5. Verify 201 success, redirect to edit page

**Step 2: Test node edit flow**

1. Navigate to the created node's edit page
2. Verify all fields are pre-populated
3. Change title, toggle Published off
4. Click Save
5. Verify success

**Step 3: Test node list**

1. Navigate to `/node`
2. Verify the created node appears in the list
3. Verify table shows relevant columns

**Step 4: Test taxonomy vocabulary create**

1. Navigate to `/taxonomy_vocabulary/create`
2. Verify form shows: Name, Description, Weight
3. Create a vocabulary named "Tags"
4. Verify success

**Step 5: Test delete**

1. From any entity list, click Delete on an entity
2. Confirm deletion
3. Verify entity is removed

**Step 6: Document any remaining issues**

Note any bugs or UX problems found during testing. These become follow-up tasks.

**Step 7: Commit (if any fixes were needed)**

```bash
git commit -m "fix: address issues found during e2e smoke test"
```

---

### Task 9: Run full test suite and verify clean state

**Files:** None

**Step 1: Run all PHP tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: PASS (all tests including new ones)

**Step 2: Run frontend tests**

Run: `cd packages/admin && npm test`
Expected: PASS (all 55+ tests — no frontend changes were made, so nothing should break)

**Step 3: Final commit if needed**

If any fixes were required, commit them with descriptive messages.

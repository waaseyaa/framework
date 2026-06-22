# Admin Surface Package Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire `packages/admin-surface` into the Waaseyaa monorepo with full test coverage, resolving GitHub issue waaseyaa/framework#417.

**Architecture:** The admin-surface package provides the canonical integration boundary between the admin SPA and host applications. It has three layers: TypeScript contract types (consumed by the SPA), PHP host base class (extended by apps), and a declarative catalog builder (used by apps to define their entity catalog). The package sits at Layer 6 (Interfaces) alongside admin and admin-bridge.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, TypeScript (contract types only — no build step)

**Issue:** waaseyaa/framework#417

---

## File Map

| File | Responsibility | Status |
|------|---------------|--------|
| `composer.json` (root) | Wire admin-surface as path repo + dependency | Modify |
| `packages/admin-surface/composer.json` | Package metadata, PSR-4, provider discovery | Exists |
| `packages/admin-surface/src/AdminSurfaceServiceProvider.php` | Route registration helper | Exists (needs fix) |
| `packages/admin-surface/src/Host/AbstractAdminSurfaceHost.php` | Base host class with abstract + handler methods | Exists (fixed: import + query) |
| `packages/admin-surface/src/Host/AdminSurfaceSessionData.php` | Session value object | Exists |
| `packages/admin-surface/src/Host/AdminSurfaceResultData.php` | Result value object (success/error factory) | Exists |
| `packages/admin-surface/src/Catalog/CatalogBuilder.php` | Declarative catalog builder | Exists |
| `packages/admin-surface/src/Catalog/EntityDefinition.php` | Entity definition with fields/actions/capabilities | Exists |
| `packages/admin-surface/src/Catalog/FieldDefinition.php` | Field metadata builder | Exists |
| `packages/admin-surface/src/Catalog/ActionDefinition.php` | Action metadata builder | Exists |
| `packages/admin-surface/contract/AdminSurfaceContract.ts` | TypeScript contract interface | Exists |
| `packages/admin-surface/contract/types.ts` | Shared TypeScript types | Exists |
| `packages/admin-surface/contract/index.ts` | Re-exports | Exists |
| `packages/admin-surface/tests/Unit/Host/AdminSurfaceSessionDataTest.php` | Session VO tests | Create |
| `packages/admin-surface/tests/Unit/Host/AdminSurfaceResultDataTest.php` | Result VO tests | Create |
| `packages/admin-surface/tests/Unit/Catalog/CatalogBuilderTest.php` | Catalog builder tests | Create |
| `packages/admin-surface/tests/Unit/Catalog/FieldDefinitionTest.php` | Field definition tests | Create |
| `packages/admin-surface/tests/Unit/Catalog/ActionDefinitionTest.php` | Action definition tests | Create |
| `packages/admin-surface/tests/Unit/Host/AbstractAdminSurfaceHostTest.php` | Host handler method tests | Create |

---

## Chunk 1: Monorepo Wiring

### Task 1: Wire admin-surface into root composer.json

**Files:**
- Modify: `composer.json` (root, lines 7-48 for repositories, lines 50-89 for require, lines 97-135 for autoload-dev)

- [ ] **Step 1: Add path repository**

Add to the `repositories` array (after `admin-bridge` on line 48):

```json
{ "type": "path", "url": "packages/admin-surface" }
```

- [ ] **Step 2: Add require entry**

Add to the `require` object (alphabetical, after `waaseyaa/admin-bridge`):

```json
"waaseyaa/admin-surface": "@dev",
```

- [ ] **Step 3: Add autoload-dev entry**

Add to the `autoload-dev.psr-4` object (after the `AdminBridge\\Tests` entry on line 134):

```json
"Waaseyaa\\AdminSurface\\Tests\\": "packages/admin-surface/tests/",
```

- [ ] **Step 4: Run composer update to wire the package**

Run: `composer update waaseyaa/admin-surface --no-interaction`
Expected: Package resolves via path symlink, no errors.

- [ ] **Step 5: Verify autoloading**

Run: `php -r "require 'vendor/autoload.php'; new \Waaseyaa\AdminSurface\Catalog\CatalogBuilder(); echo 'OK';"`
Expected: `OK`

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat(#417): wire admin-surface package into monorepo"
```

---

### Task 2: Fix AdminSurfaceServiceProvider route registration and Host import

Two issues already fixed in source (verify they're correct):
1. `registerRoutes()` used `RouteBuilder::register($router)` which doesn't exist — fixed to `->build()` + `$router->addRoute()`.
2. `AbstractAdminSurfaceHost` imported non-existent `Waaseyaa\Foundation\Http\HttpRequest` — fixed to `Symfony\Component\HttpFoundation\Request`. Also fixed `$request->query` → `$request->query->all()` (ParameterBag → array).

**Files:**
- Modify: `packages/admin-surface/src/AdminSurfaceServiceProvider.php`

- [ ] **Step 1: Read the current file**

Read `packages/admin-surface/src/AdminSurfaceServiceProvider.php` to confirm the issue.

- [ ] **Step 2: Rewrite registerRoutes() to use correct RouteBuilder API**

Replace the `registerRoutes()` method body with:

```php
public static function registerRoutes(WaaseyaaRouter $router, AbstractAdminSurfaceHost $host): void
{
    $router->addRoute('admin_surface.session', RouteBuilder::create('/admin/surface/session')
        ->methods('GET')
        ->requireAuthentication()
        ->controller(fn ($request) => $host->handleSession($request))
        ->build());

    $router->addRoute('admin_surface.catalog', RouteBuilder::create('/admin/surface/catalog')
        ->methods('GET')
        ->requireAuthentication()
        ->controller(fn ($request) => $host->handleCatalog($request))
        ->build());

    $router->addRoute('admin_surface.list', RouteBuilder::create('/admin/surface/{type}')
        ->methods('GET')
        ->requireAuthentication()
        ->controller(fn ($request, $type) => $host->handleList($request, $type))
        ->build());

    $router->addRoute('admin_surface.get', RouteBuilder::create('/admin/surface/{type}/{id}')
        ->methods('GET')
        ->requireAuthentication()
        ->controller(fn ($request, $type, $id) => $host->handleGet($request, $type, $id))
        ->build());

    $router->addRoute('admin_surface.action', RouteBuilder::create('/admin/surface/{type}/action/{action}')
        ->methods('POST')
        ->requireAuthentication()
        ->controller(fn ($request, $type, $action) => $host->handleAction($request, $type, $action))
        ->build());
}
```

- [ ] **Step 3: Remove unused imports**

Remove `RouteBuilder` if it was imported but check — it's still used. Remove `WaaseyaaRouter` import duplication if any.

- [ ] **Step 4: Commit**

```bash
git add packages/admin-surface/src/AdminSurfaceServiceProvider.php
git commit -m "fix(#417): use correct RouteBuilder API in AdminSurfaceServiceProvider"
```

---

## Chunk 2: Value Object Tests

### Task 3: Unit tests for AdminSurfaceSessionData

**Files:**
- Create: `packages/admin-surface/tests/Unit/Host/AdminSurfaceSessionDataTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;

#[CoversClass(AdminSurfaceSessionData::class)]
final class AdminSurfaceSessionDataTest extends TestCase
{
    #[Test]
    public function toArrayReturnsFullStructure(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '42',
            accountName: 'Admin User',
            roles: ['admin', 'editor'],
            policies: ['administer content', 'edit any content'],
            email: 'admin@example.com',
            tenantId: 'org-1',
            tenantName: 'Test Org',
            features: ['ai_assist' => true],
        );

        $result = $session->toArray();

        self::assertSame('42', $result['account']['id']);
        self::assertSame('Admin User', $result['account']['name']);
        self::assertSame('admin@example.com', $result['account']['email']);
        self::assertSame(['admin', 'editor'], $result['account']['roles']);
        self::assertSame('org-1', $result['tenant']['id']);
        self::assertSame('Test Org', $result['tenant']['name']);
        self::assertSame(['administer content', 'edit any content'], $result['policies']);
        self::assertSame(['ai_assist' => true], $result['features']);
    }

    #[Test]
    public function toArrayUsesDefaultsForOptionalFields(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'User',
            roles: [],
            policies: [],
        );

        $result = $session->toArray();

        self::assertNull($result['account']['email']);
        self::assertSame('default', $result['tenant']['id']);
        self::assertSame('Default', $result['tenant']['name']);
        // Empty features array becomes stdClass for clean JSON serialization
        self::assertInstanceOf(\stdClass::class, $result['features']);
    }
}
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/Unit/Host/AdminSurfaceSessionDataTest.php`
Expected: 2 tests, 2 passed.

- [ ] **Step 3: Commit**

```bash
git add packages/admin-surface/tests/Unit/Host/AdminSurfaceSessionDataTest.php
git commit -m "test(#417): add AdminSurfaceSessionData unit tests"
```

---

### Task 4: Unit tests for AdminSurfaceResultData

**Files:**
- Create: `packages/admin-surface/tests/Unit/Host/AdminSurfaceResultDataTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;

#[CoversClass(AdminSurfaceResultData::class)]
final class AdminSurfaceResultDataTest extends TestCase
{
    #[Test]
    public function successWithData(): void
    {
        $result = AdminSurfaceResultData::success(['name' => 'Test']);

        $array = $result->toArray();

        self::assertTrue($array['ok']);
        self::assertSame(['name' => 'Test'], $array['data']);
        self::assertArrayNotHasKey('error', $array);
    }

    #[Test]
    public function successWithMeta(): void
    {
        $result = AdminSurfaceResultData::success(
            ['id' => '1'],
            ['total' => 42],
        );

        $array = $result->toArray();

        self::assertTrue($array['ok']);
        self::assertSame(['total' => 42], $array['meta']);
    }

    #[Test]
    public function errorWithStatusAndTitle(): void
    {
        $result = AdminSurfaceResultData::error(404, 'Not Found');

        $array = $result->toArray();

        self::assertFalse($array['ok']);
        self::assertSame(404, $array['error']['status']);
        self::assertSame('Not Found', $array['error']['title']);
        self::assertArrayNotHasKey('detail', $array['error']);
        self::assertArrayNotHasKey('data', $array);
    }

    #[Test]
    public function errorWithDetail(): void
    {
        $result = AdminSurfaceResultData::error(
            422,
            'Validation Failed',
            'The title field is required.',
        );

        $array = $result->toArray();

        self::assertFalse($array['ok']);
        self::assertSame('Validation Failed', $array['error']['title']);
        self::assertSame('The title field is required.', $array['error']['detail']);
    }
}
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/Unit/Host/AdminSurfaceResultDataTest.php`
Expected: 4 tests, 4 passed.

- [ ] **Step 3: Commit**

```bash
git add packages/admin-surface/tests/Unit/Host/AdminSurfaceResultDataTest.php
git commit -m "test(#417): add AdminSurfaceResultData unit tests"
```

---

## Chunk 3: Catalog Builder Tests

### Task 5: Unit tests for FieldDefinition

**Files:**
- Create: `packages/admin-surface/tests/Unit/Catalog/FieldDefinitionTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Catalog\FieldDefinition;

#[CoversClass(FieldDefinition::class)]
final class FieldDefinitionTest extends TestCase
{
    #[Test]
    public function minimalFieldToArray(): void
    {
        $field = new FieldDefinition('title', 'Title', 'string');

        $array = $field->toArray();

        self::assertSame('title', $array['name']);
        self::assertSame('Title', $array['label']);
        self::assertSame('string', $array['type']);
        self::assertArrayNotHasKey('widget', $array);
        self::assertArrayNotHasKey('weight', $array);
        self::assertArrayNotHasKey('required', $array);
    }

    #[Test]
    public function fullyConfiguredField(): void
    {
        $field = new FieldDefinition('body', 'Body', 'string');
        $field->widget('richtext')
            ->weight(10)
            ->required()
            ->accessRestricted()
            ->options(['maxLength' => 5000]);

        $array = $field->toArray();

        self::assertSame('richtext', $array['widget']);
        self::assertSame(10, $array['weight']);
        self::assertTrue($array['required']);
        self::assertTrue($array['accessRestricted']);
        self::assertSame(['maxLength' => 5000], $array['options']);
    }

    #[Test]
    public function readOnlyField(): void
    {
        $field = new FieldDefinition('uuid', 'UUID', 'string');
        $field->readOnly();

        $array = $field->toArray();

        self::assertTrue($array['readOnly']);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/Unit/Catalog/FieldDefinitionTest.php`
Expected: 3 tests, 3 passed.

- [ ] **Step 3: Commit**

```bash
git add packages/admin-surface/tests/Unit/Catalog/FieldDefinitionTest.php
git commit -m "test(#417): add FieldDefinition unit tests"
```

---

### Task 6: Unit tests for ActionDefinition

**Files:**
- Create: `packages/admin-surface/tests/Unit/Catalog/ActionDefinitionTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Catalog\ActionDefinition;

#[CoversClass(ActionDefinition::class)]
final class ActionDefinitionTest extends TestCase
{
    #[Test]
    public function minimalActionToArray(): void
    {
        $action = new ActionDefinition('publish', 'Publish');

        $array = $action->toArray();

        self::assertSame('publish', $array['id']);
        self::assertSame('Publish', $array['label']);
        self::assertSame('entity', $array['scope']);
        self::assertArrayNotHasKey('confirmation', $array);
        self::assertArrayNotHasKey('dangerous', $array);
    }

    #[Test]
    public function dangerousActionWithConfirmation(): void
    {
        $action = new ActionDefinition('delete', 'Delete');
        $action->confirm('Are you sure you want to delete this?')
            ->dangerous();

        $array = $action->toArray();

        self::assertSame('Are you sure you want to delete this?', $array['confirmation']);
        self::assertTrue($array['dangerous']);
    }

    #[Test]
    public function collectionScopeAction(): void
    {
        $action = new ActionDefinition('bulk_delete', 'Delete Selected');
        $action->collection()->dangerous();

        $array = $action->toArray();

        self::assertSame('collection', $array['scope']);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/Unit/Catalog/ActionDefinitionTest.php`
Expected: 3 tests, 3 passed.

- [ ] **Step 3: Commit**

```bash
git add packages/admin-surface/tests/Unit/Catalog/ActionDefinitionTest.php
git commit -m "test(#417): add ActionDefinition unit tests"
```

---

### Task 7: Unit tests for CatalogBuilder + EntityDefinition

**Files:**
- Create: `packages/admin-surface/tests/Unit/Catalog/CatalogBuilderTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Catalog\EntityDefinition;

#[CoversClass(CatalogBuilder::class)]
#[CoversClass(EntityDefinition::class)]
final class CatalogBuilderTest extends TestCase
{
    #[Test]
    public function emptyBuilderReturnsEmptyArray(): void
    {
        $builder = new CatalogBuilder();

        self::assertSame([], $builder->build());
    }

    #[Test]
    public function defineEntityReturnsFluentEntityDefinition(): void
    {
        $builder = new CatalogBuilder();
        $entity = $builder->defineEntity('node', 'Content');

        self::assertInstanceOf(EntityDefinition::class, $entity);
    }

    #[Test]
    public function buildReturnsAllDefinedEntities(): void
    {
        $builder = new CatalogBuilder();
        $builder->defineEntity('node', 'Content')->group('content');
        $builder->defineEntity('user', 'Users')->group('admin');

        $result = $builder->build();

        self::assertCount(2, $result);
        self::assertSame('node', $result[0]['id']);
        self::assertSame('Content', $result[0]['label']);
        self::assertSame('content', $result[0]['group']);
        self::assertSame('user', $result[1]['id']);
        self::assertSame('admin', $result[1]['group']);
    }

    #[Test]
    public function entityWithFieldsAndActions(): void
    {
        $builder = new CatalogBuilder();
        $entity = $builder->defineEntity('article', 'Articles');
        $entity->field('title', 'Title', 'string')->required()->widget('text');
        $entity->field('body', 'Body', 'string')->widget('richtext')->weight(10);
        $entity->action('publish', 'Publish');
        $entity->action('delete', 'Delete')->dangerous()->confirm('Are you sure?');

        $result = $builder->build();

        self::assertCount(2, $result[0]['fields']);
        self::assertSame('title', $result[0]['fields'][0]['name']);
        self::assertTrue($result[0]['fields'][0]['required']);
        self::assertSame('richtext', $result[0]['fields'][1]['widget']);

        self::assertCount(2, $result[0]['actions']);
        self::assertSame('publish', $result[0]['actions'][0]['id']);
        self::assertTrue($result[0]['actions'][1]['dangerous']);
    }

    #[Test]
    public function defaultCapabilitiesAreAllTrue(): void
    {
        $builder = new CatalogBuilder();
        $builder->defineEntity('node', 'Content');

        $result = $builder->build();
        $caps = $result[0]['capabilities'];

        self::assertTrue($caps['list']);
        self::assertTrue($caps['get']);
        self::assertTrue($caps['create']);
        self::assertTrue($caps['update']);
        self::assertTrue($caps['delete']);
        self::assertTrue($caps['schema']);
    }

    #[Test]
    public function readOnlyDisablesCrudCapabilities(): void
    {
        $builder = new CatalogBuilder();
        $builder->defineEntity('log', 'Logs')->readOnly();

        $result = $builder->build();
        $caps = $result[0]['capabilities'];

        self::assertTrue($caps['list']);
        self::assertTrue($caps['get']);
        self::assertFalse($caps['create']);
        self::assertFalse($caps['update']);
        self::assertFalse($caps['delete']);
    }

    #[Test]
    public function customCapabilities(): void
    {
        $builder = new CatalogBuilder();
        $builder->defineEntity('config', 'Config')
            ->capabilities(['delete' => false, 'schema' => false]);

        $result = $builder->build();
        $caps = $result[0]['capabilities'];

        self::assertTrue($caps['list']);
        self::assertTrue($caps['create']);
        self::assertFalse($caps['delete']);
        self::assertFalse($caps['schema']);
    }

    #[Test]
    public function invalidCapabilityThrows(): void
    {
        $entity = new EntityDefinition('test', 'Test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown capability: fly');

        $entity->capabilities(['fly' => true]);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/Unit/Catalog/CatalogBuilderTest.php`
Expected: 8 tests, 8 passed.

- [ ] **Step 3: Commit**

```bash
git add packages/admin-surface/tests/Unit/Catalog/CatalogBuilderTest.php
git commit -m "test(#417): add CatalogBuilder and EntityDefinition unit tests"
```

---

## Chunk 4: Host Handler Tests

### Task 8: Unit tests for AbstractAdminSurfaceHost handler methods

Tests use a concrete anonymous subclass to test the handler methods (which delegate to abstract methods).

**Files:**
- Create: `packages/admin-surface/tests/Unit/Host/AbstractAdminSurfaceHostTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(AbstractAdminSurfaceHost::class)]
final class AbstractAdminSurfaceHostTest extends TestCase
{
    private function createHost(
        ?AdminSurfaceSessionData $session = null,
        ?CatalogBuilder $catalog = null,
    ): AbstractAdminSurfaceHost {
        return new class ($session, $catalog) extends AbstractAdminSurfaceHost {
            public function __construct(
                private readonly ?AdminSurfaceSessionData $session,
                private readonly ?CatalogBuilder $catalog,
            ) {
            }

            public function resolveSession(Request $request): ?AdminSurfaceSessionData
            {
                return $this->session;
            }

            public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
            {
                return $this->catalog ?? new CatalogBuilder();
            }

            public function list(string $type, array $query = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    'entities' => [],
                    'total' => 0,
                ]);
            }

            public function get(string $type, string $id): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    'type' => $type,
                    'id' => $id,
                    'attributes' => ['title' => 'Test'],
                ]);
            }

            public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success(['action' => $action]);
            }
        };
    }

    private function createRequest(string $method = 'GET', string $content = ''): Request
    {
        return Request::create('/admin/surface/session', $method, content: $content);
    }

    #[Test]
    public function handleSessionReturnsUnauthorizedWhenNoSession(): void
    {
        $host = $this->createHost(session: null);
        $request = $this->createRequest();

        $result = $host->handleSession($request);

        self::assertFalse($result['ok']);
        self::assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleSessionReturnsSessionData(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['admin'],
            policies: ['administer content'],
        );
        $host = $this->createHost(session: $session);
        $request = $this->createRequest();

        $result = $host->handleSession($request);

        self::assertTrue($result['ok']);
        self::assertSame('1', $result['data']['account']['id']);
        self::assertSame(['admin'], $result['data']['account']['roles']);
    }

    #[Test]
    public function handleCatalogReturnsUnauthorizedWhenNoSession(): void
    {
        $host = $this->createHost(session: null);
        $request = $this->createRequest();

        $result = $host->handleCatalog($request);

        self::assertFalse($result['ok']);
        self::assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleCatalogReturnsCatalogEntries(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['admin'],
            policies: [],
        );
        $catalog = new CatalogBuilder();
        $catalog->defineEntity('node', 'Content')->group('content');

        $host = $this->createHost(session: $session, catalog: $catalog);
        $request = $this->createRequest();

        $result = $host->handleCatalog($request);

        self::assertTrue($result['ok']);
        self::assertCount(1, $result['data']['entities']);
        self::assertSame('node', $result['data']['entities'][0]['id']);
    }

    #[Test]
    public function handleListReturnsUnauthorizedWhenNoSession(): void
    {
        $host = $this->createHost(session: null);
        $request = $this->createRequest();

        $result = $host->handleList($request, 'node');

        self::assertFalse($result['ok']);
    }

    #[Test]
    public function handleListDelegatesToListMethod(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
        );
        $host = $this->createHost(session: $session);
        $request = $this->createRequest();

        $result = $host->handleList($request, 'node');

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['data']['total']);
    }

    #[Test]
    public function handleGetDelegatesToGetMethod(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
        );
        $host = $this->createHost(session: $session);
        $request = $this->createRequest();

        $result = $host->handleGet($request, 'node', '42');

        self::assertTrue($result['ok']);
        self::assertSame('42', $result['data']['id']);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/Unit/Host/AbstractAdminSurfaceHostTest.php`
Expected: 7 tests, 7 passed.

- [ ] **Step 3: Commit**

```bash
git add packages/admin-surface/tests/Unit/Host/AbstractAdminSurfaceHostTest.php
git commit -m "test(#417): add AbstractAdminSurfaceHost handler tests"
```

---

## Chunk 5: Final Verification

### Task 9: Run full test suite and verify

- [ ] **Step 1: Run all admin-surface tests**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/`
Expected: All tests pass (20 tests total across 6 files).

- [ ] **Step 2: Run the full monorepo test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: No regressions. All existing tests still pass.

- [ ] **Step 3: Verify PHPStan (if configured for the package)**

Run: `./vendor/bin/phpstan analyse packages/admin-surface/src/ --level=5`
Expected: No errors.

- [ ] **Step 4: Final commit if any fixes were needed**

```bash
git add -A packages/admin-surface/
git commit -m "fix(#417): resolve test/static analysis issues in admin-surface"
```

---

## Summary

| Task | Description | Tests |
|------|-------------|-------|
| 1 | Wire into root composer.json | — |
| 2 | Fix ServiceProvider route registration | — |
| 3 | AdminSurfaceSessionData tests | 2 |
| 4 | AdminSurfaceResultData tests | 4 |
| 5 | FieldDefinition tests | 3 |
| 6 | ActionDefinition tests | 3 |
| 7 | CatalogBuilder + EntityDefinition tests | 8 |
| 8 | AbstractAdminSurfaceHost handler tests | 7 |
| 9 | Full suite verification | — |

**Total:** 9 tasks, ~27 tests, ~8 commits

# Authorization Wiring Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Wire existing access primitives into the middleware pipeline and package discovery from PR #4, so HTTP requests flow through session resolution and route-level authorization.

**Architecture:** Pipeline-first approach. SessionMiddleware resolves the current user from PHP sessions. AuthorizationMiddleware delegates to the existing AccessChecker. Discovery extensions collect permissions from composer.json and policies from attributes. A minimal shim in public/index.php wraps existing dispatch in HttpPipeline.

**Tech Stack:** PHP 8.3, Symfony HttpFoundation, PHPUnit 10.5, existing Waaseyaa middleware/access/entity packages.

---

### Task 1: PackageManifest — add permissions and policies properties

**Files:**
- Modify: `packages/foundation/src/Discovery/PackageManifest.php`
- Test: `packages/foundation/tests/Unit/Discovery/PackageManifestTest.php`

**Step 1: Write the failing tests**

Add to `PackageManifestTest.php`:

```php
#[Test]
public function defaults_include_permissions_and_policies(): void
{
    $manifest = new PackageManifest();
    $this->assertSame([], $manifest->permissions);
    $this->assertSame([], $manifest->policies);
}

#[Test]
public function round_trips_permissions_and_policies_through_array(): void
{
    $manifest = new PackageManifest(
        permissions: [
            'access content' => ['title' => 'Access published content'],
            'create article' => ['title' => 'Create Article content', 'description' => 'Allows creating article nodes'],
        ],
        policies: [
            'node' => 'App\\Policy\\NodePolicy',
        ],
    );

    $array = $manifest->toArray();
    $restored = PackageManifest::fromArray($array);

    $this->assertSame($manifest->permissions, $restored->permissions);
    $this->assertSame($manifest->policies, $restored->policies);
}
```

Also update the existing `from_array_throws_on_missing_keys` test — it will need `permissions` and `policies` in the required keys.

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/PackageManifestTest.php`
Expected: FAIL — `permissions` and `policies` properties don't exist yet.

**Step 3: Implement the changes to PackageManifest**

In `PackageManifest.php`:
- Add two new constructor properties:
  ```php
  /** @var array<string, array{title: string, description?: string}> */
  public readonly array $permissions = [],
  /** @var array<string, string> */
  public readonly array $policies = [],
  ```
- In `toArray()`, add:
  ```php
  'permissions' => $this->permissions,
  'policies' => $this->policies,
  ```
- In `fromArray()`:
  - Add `'permissions'` and `'policies'` to `$requiredKeys`
  - Add to constructor call:
    ```php
    permissions: $data['permissions'],
    policies: $data['policies'],
    ```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/PackageManifestTest.php`
Expected: ALL PASS

**Step 5: Update existing tests that build manifest arrays**

The `PackageManifestCompilerTest::load_uses_cache_when_available` test hardcodes a `$data` array for `fromArray()`. It will now fail because `permissions` and `policies` are missing. Add them:

```php
$data = [
    'providers' => ['CachedProvider'],
    'commands' => [],
    'routes' => [],
    'migrations' => [],
    'field_types' => [],
    'listeners' => [],
    'middleware' => [],
    'permissions' => [],
    'policies' => [],
];
```

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/`
Expected: ALL PASS

**Step 6: Commit**

```bash
git add packages/foundation/src/Discovery/PackageManifest.php packages/foundation/tests/Unit/Discovery/PackageManifestTest.php packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php
git commit -m "feat(foundation): add permissions and policies to PackageManifest"
```

---

### Task 2: PackageManifestCompiler — collect permissions from composer.json

**Files:**
- Modify: `packages/foundation/src/Discovery/PackageManifestCompiler.php`
- Test: `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`

**Step 1: Write the failing test**

Add to `PackageManifestCompilerTest.php`:

```php
#[Test]
public function compile_collects_permissions_from_installed_json(): void
{
    $installed = [
        'packages' => [
            [
                'name' => 'waaseyaa/node',
                'extra' => [
                    'waaseyaa' => [
                        'permissions' => [
                            'access content' => ['title' => 'Access published content'],
                            'create article' => ['title' => 'Create Article', 'description' => 'Create article nodes'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'waaseyaa/user',
                'extra' => [
                    'waaseyaa' => [
                        'permissions' => [
                            'administer users' => ['title' => 'Administer users'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    file_put_contents(
        $this->tempDir . '/vendor/composer/installed.json',
        json_encode($installed, JSON_THROW_ON_ERROR),
    );

    $storagePath = $this->tempDir . '/storage';
    $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
    $manifest = $compiler->compile();

    $this->assertCount(3, $manifest->permissions);
    $this->assertSame('Access published content', $manifest->permissions['access content']['title']);
    $this->assertSame('Administer users', $manifest->permissions['administer users']['title']);
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter compile_collects_permissions packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`
Expected: FAIL — permissions are not collected yet (empty array).

**Step 3: Implement permission collection**

In `PackageManifestCompiler::compile()`, after the existing `foreach ($packages as $package)` loop body, add inside the loop:

```php
if (isset($extra['permissions']) && is_array($extra['permissions'])) {
    foreach ($extra['permissions'] as $permId => $permDef) {
        $permissions[$permId] = $permDef;
    }
}
```

Initialize `$permissions = [];` at the top of the method alongside the other variables.

Pass `permissions: $permissions` to the `PackageManifest` constructor at the bottom.

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter compile_collects_permissions packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`
Expected: PASS

**Step 5: Run all discovery tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/`
Expected: ALL PASS

**Step 6: Commit**

```bash
git add packages/foundation/src/Discovery/PackageManifestCompiler.php packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php
git commit -m "feat(foundation): collect permissions from composer.json extra"
```

---

### Task 3: PackageManifestCompiler — discover policies via PolicyAttribute

**Files:**
- Modify: `packages/foundation/src/Discovery/PackageManifestCompiler.php`
- Test: `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`

**Context:** The `PolicyAttribute` class (`Waaseyaa\Access\Gate\PolicyAttribute`) is a PHP attribute with `entityType: string`. The compiler's `scanClasses()` method scans the autoload classmap for Waaseyaa\ classes with known attributes. We need to add `PolicyAttribute` to the scan.

**Step 1: Write the failing test**

This is hard to unit test directly because `scanClasses()` reads from the autoload classmap. Instead, test that the `compile()` output includes policies when the classmap contains a class with `#[PolicyAttribute]`.

The existing tests skip class scanning (no autoload_classmap.php in the temp dir). For this test, we need a real classmap. The simplest approach: create a fixture class in the test, write an autoload_classmap.php that points to it.

Create a test fixture class. Add to the test file:

```php
#[Test]
public function compile_discovers_policy_classes(): void
{
    // Create a fixture policy class file
    $fixtureDir = $this->tempDir . '/src';
    mkdir($fixtureDir, 0o755, true);

    $fixtureClass = <<<'PHP'
    <?php
    declare(strict_types=1);
    namespace Waaseyaa\TestFixtures;
    use Waaseyaa\Access\Gate\PolicyAttribute;
    #[PolicyAttribute(entityType: 'node')]
    final class NodePolicy {}
    PHP;

    file_put_contents($fixtureDir . '/NodePolicy.php', $fixtureClass);

    // Register the class so it can be reflected
    require_once $fixtureDir . '/NodePolicy.php';

    // Write an autoload_classmap that includes our fixture
    file_put_contents(
        $this->tempDir . '/vendor/composer/autoload_classmap.php',
        '<?php return [\'Waaseyaa\\\\TestFixtures\\\\NodePolicy\' => \'' . $fixtureDir . '/NodePolicy.php\'];',
    );

    // Write empty installed.json
    file_put_contents(
        $this->tempDir . '/vendor/composer/installed.json',
        json_encode(['packages' => []], JSON_THROW_ON_ERROR),
    );

    $storagePath = $this->tempDir . '/storage';
    $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
    $manifest = $compiler->compile();

    $this->assertSame('Waaseyaa\\TestFixtures\\NodePolicy', $manifest->policies['node'] ?? null);
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter compile_discovers_policy packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`
Expected: FAIL — policies array is empty.

**Step 3: Implement policy discovery**

In `PackageManifestCompiler`:

1. Add import at top: `use Waaseyaa\Access\Gate\PolicyAttribute;`

2. In `scanClasses()`, add `PolicyAttribute` to the `$hasDiscoveryAttribute` check:
   ```php
   $hasDiscoveryAttribute = !empty($ref->getAttributes(AsFieldType::class))
       || !empty($ref->getAttributes(Listener::class))
       || !empty($ref->getAttributes(AsMiddleware::class))
       || !empty($ref->getAttributes(AsEntityType::class))
       || !empty($ref->getAttributes(PolicyAttribute::class));
   ```

3. In `compile()`, initialize `$policies = [];` alongside other variables.

4. In the class scanning loop (after existing attribute checks), add:
   ```php
   foreach ($ref->getAttributes(PolicyAttribute::class) as $attr) {
       $instance = $attr->newInstance();
       $policies[$instance->entityType] = $class;
   }
   ```

5. Pass `policies: $policies` to the `PackageManifest` constructor.

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter compile_discovers_policy packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`
Expected: PASS

**Step 5: Run all discovery tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Discovery/`
Expected: ALL PASS

**Step 6: Commit**

```bash
git add packages/foundation/src/Discovery/PackageManifestCompiler.php packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php
git commit -m "feat(foundation): discover Gate policies via PolicyAttribute scanning"
```

---

### Task 4: SessionMiddleware

**Files:**
- Create: `packages/user/src/Middleware/SessionMiddleware.php`
- Create: `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`
- Modify: `packages/user/composer.json` (add `waaseyaa/foundation` + `symfony/http-foundation` deps)

**Step 1: Add dependencies to user package composer.json**

The `user` package needs `waaseyaa/foundation` (for `HttpMiddlewareInterface`) and `symfony/http-foundation` (for `Request`/`Response`). `foundation` is already a transitive dep via `entity`, but make it explicit.

In `packages/user/composer.json`, add to `repositories`:
```json
{"type": "path", "url": "../foundation"},
{"type": "path", "url": "../queue"}
```

Add to `require`:
```json
"waaseyaa/foundation": "@dev",
"symfony/http-foundation": "^7.0"
```

**Step 2: Write the failing tests**

Create `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`:

```php
<?php
declare(strict_types=1);
namespace Waaseyaa\User\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\User;

#[CoversClass(SessionMiddleware::class)]
final class SessionMiddlewareTest extends TestCase
{
    #[Test]
    public function sets_anonymous_user_when_no_session(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->never())->method('load');

        $middleware = new SessionMiddleware($storage);
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

        $this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
    }

    #[Test]
    public function resolves_user_from_session(): void
    {
        $user = new User(['uid' => 42, 'name' => 'admin', 'permissions' => ['access content']]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('load')
            ->with(42)
            ->willReturn($user);

        $middleware = new SessionMiddleware($storage);
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

    #[Test]
    public function falls_back_to_anonymous_when_user_not_found(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())
            ->method('load')
            ->with(999)
            ->willReturn(null);

        $middleware = new SessionMiddleware($storage);
        $request = Request::create('/test');
        $request->attributes->set('_session', ['waaseyaa_uid' => 999]);

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

        $this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
    }

    #[Test]
    public function passes_response_from_next_handler(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $middleware = new SessionMiddleware($storage);
        $request = Request::create('/test');

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('downstream', 201);
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('downstream', $response->getContent());
    }
}
```

**Note on session handling:** In tests we avoid `session_start()` by reading from `$request->attributes->get('_session')` as a test-friendly override. In production, the middleware reads `$_SESSION` directly. The implementation handles both: checks `_session` attribute first (for testing/custom session backends), falls back to `$_SESSION`.

**Step 3: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`
Expected: FAIL — class `SessionMiddleware` does not exist.

**Step 4: Write SessionMiddleware implementation**

Create `packages/user/src/Middleware/SessionMiddleware.php`:

```php
<?php
declare(strict_types=1);
namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\User\AnonymousUser;

final class SessionMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly EntityStorageInterface $userStorage,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $account = $this->resolveAccount($request);
        $request->attributes->set('_account', $account);

        return $next->handle($request);
    }

    private function resolveAccount(Request $request): \Waaseyaa\Access\AccountInterface
    {
        $session = $request->attributes->get('_session') ?? ($_SESSION ?? []);
        $uid = $session['waaseyaa_uid'] ?? null;

        if ($uid === null) {
            return new AnonymousUser();
        }

        $user = $this->userStorage->load($uid);

        if ($user instanceof \Waaseyaa\Access\AccountInterface) {
            return $user;
        }

        return new AnonymousUser();
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`
Expected: ALL PASS

**Step 6: Commit**

```bash
git add packages/user/src/Middleware/SessionMiddleware.php packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php packages/user/composer.json
git commit -m "feat(user): add SessionMiddleware for request user resolution"
```

---

### Task 5: AuthorizationMiddleware

**Files:**
- Create: `packages/access/src/Middleware/AuthorizationMiddleware.php`
- Create: `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`
- Modify: `packages/access/composer.json` (add `waaseyaa/foundation` + `symfony/http-foundation` deps)

**Step 1: Add dependencies to access package composer.json**

In `packages/access/composer.json`, add to `repositories`:
```json
{"type": "path", "url": "../foundation"},
{"type": "path", "url": "../queue"}
```

Add to `require`:
```json
"waaseyaa/foundation": "@dev",
"symfony/http-foundation": "^7.0"
```

Also add `waaseyaa/routing` since `AuthorizationMiddleware` delegates to `AccessChecker`:
```json
{"type": "path", "url": "../routing"}
```
in repositories, and:
```json
"waaseyaa/routing": "@dev"
```
in require.

**Step 2: Write the failing tests**

Create `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`:

```php
<?php
declare(strict_types=1);
namespace Waaseyaa\Access\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Routing\AccessChecker;
use Waaseyaa\User\AnonymousUser;

#[CoversClass(AuthorizationMiddleware::class)]
final class AuthorizationMiddlewareTest extends TestCase
{
    #[Test]
    public function returns_403_when_access_is_forbidden(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_permission', 'access content');

        $account = new AnonymousUser();

        $accessChecker = new AccessChecker();

        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('should not reach here');
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('403', $response->getContent());
    }

    #[Test]
    public function passes_through_when_access_is_allowed(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_public', true);

        $account = new AnonymousUser();

        $accessChecker = new AccessChecker();

        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/node');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('downstream', 200);
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('downstream', $response->getContent());
    }

    #[Test]
    public function passes_through_when_no_access_requirements(): void
    {
        $route = new Route('/api/test');
        // No access options set — open by default

        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/test');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_route_object', $route);

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('open', 200);
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function passes_through_when_no_route_object(): void
    {
        $account = new AnonymousUser();
        $accessChecker = new AccessChecker();
        $middleware = new AuthorizationMiddleware($accessChecker);

        $request = Request::create('/api/test');
        $request->attributes->set('_account', $account);
        // No _route_object — no route to check

        $next = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('no route', 200);
            }
        };

        $response = $middleware->process($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }
}
```

**Step 3: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`
Expected: FAIL — class `AuthorizationMiddleware` does not exist.

**Step 4: Write AuthorizationMiddleware implementation**

Create `packages/access/src/Middleware/AuthorizationMiddleware.php`:

```php
<?php
declare(strict_types=1);
namespace Waaseyaa\Access\Middleware;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Routing\AccessChecker;
use Waaseyaa\User\AnonymousUser;

final class AuthorizationMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly AccessChecker $accessChecker,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $route = $request->attributes->get('_route_object');

        if ($route === null) {
            return $next->handle($request);
        }

        $account = $request->attributes->get('_account') ?? new AnonymousUser();

        $result = $this->accessChecker->check($route, $account);

        if ($result->isForbidden()) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '403',
                    'title' => 'Forbidden',
                    'detail' => $result->reason,
                ]],
            ], 403, ['Content-Type' => 'application/vnd.api+json']);
        }

        return $next->handle($request);
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`
Expected: ALL PASS

**Step 6: Commit**

```bash
git add packages/access/src/Middleware/AuthorizationMiddleware.php packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php packages/access/composer.json
git commit -m "feat(access): add AuthorizationMiddleware for route access enforcement"
```

---

### Task 6: Integration test — full authorization pipeline

**Files:**
- Create: `tests/Integration/Phase11/AuthorizationPipelineTest.php`

**Step 1: Write the integration test**

Create `tests/Integration/Phase11/AuthorizationPipelineTest.php`:

```php
<?php
declare(strict_types=1);
namespace Waaseyaa\Tests\Integration\Phase11;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Routing\AccessChecker;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\User;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversNothing]
final class AuthorizationPipelineTest extends TestCase
{
    private PdoDatabase $database;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function (EntityType $def) use ($dispatcher): SqlEntityStorage {
                $schema = new SqlSchemaHandler($def, $this->database);
                $schema->ensureTable();
                return new SqlEntityStorage($def, $this->database, $dispatcher);
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
    }

    #[Test]
    public function anonymous_is_denied_on_permission_protected_route(): void
    {
        $pipeline = $this->buildPipeline();
        $request = $this->buildRequest('/api/node', '_permission', 'access content');

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function authenticated_user_without_permission_is_denied(): void
    {
        $user = new User(['uid' => 1, 'name' => 'editor', 'permissions' => []]);
        $this->entityTypeManager->getStorage('user')->save($user);

        $pipeline = $this->buildPipeline();
        $request = $this->buildRequest('/api/node', '_permission', 'access content');
        $request->attributes->set('_session', ['waaseyaa_uid' => 1]);

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function authenticated_user_with_permission_passes_through(): void
    {
        $user = new User(['uid' => 2, 'name' => 'admin', 'permissions' => ['access content']]);
        $this->entityTypeManager->getStorage('user')->save($user);

        $pipeline = $this->buildPipeline();
        $request = $this->buildRequest('/api/node', '_permission', 'access content');
        $request->attributes->set('_session', ['waaseyaa_uid' => 2]);

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', $response->getContent());
    }

    #[Test]
    public function public_route_allows_anonymous(): void
    {
        $pipeline = $this->buildPipeline();
        $request = $this->buildRequest('/public', '_public', true);

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function route_with_no_requirements_allows_anonymous(): void
    {
        $pipeline = $this->buildPipeline();

        $route = new Route('/open');
        $request = Request::create('/open');
        $request->attributes->set('_route_object', $route);

        $response = $pipeline->handle($request, $this->successHandler());

        $this->assertSame(200, $response->getStatusCode());
    }

    private function buildPipeline(): HttpPipeline
    {
        $userStorage = $this->entityTypeManager->getStorage('user');
        $accessChecker = new AccessChecker();

        return (new HttpPipeline())
            ->withMiddleware(new SessionMiddleware($userStorage))
            ->withMiddleware(new AuthorizationMiddleware($accessChecker));
    }

    private function buildRequest(string $path, string $optionKey, mixed $optionValue): Request
    {
        $route = new Route($path);
        $route->setOption($optionKey, $optionValue);

        $request = Request::create($path);
        $request->attributes->set('_route_object', $route);

        return $request;
    }

    private function successHandler(): HttpHandlerInterface
    {
        return new class implements HttpHandlerInterface {
            public function handle(Request $request): Response
            {
                return new Response('success', 200);
            }
        };
    }
}
```

**Step 2: Run the integration tests**

Run: `./vendor/bin/phpunit tests/Integration/Phase11/AuthorizationPipelineTest.php`
Expected: ALL PASS (all components are already built from Tasks 1-5)

**Step 3: Run all tests to check for regressions**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: ALL PASS

**Step 4: Commit**

```bash
git add tests/Integration/Phase11/AuthorizationPipelineTest.php
git commit -m "test: integration test for session + authorization pipeline (Phase 11)"
```

---

### Task 7: Shim public/index.php to use the pipeline

**Files:**
- Modify: `public/index.php`

**Step 1: Read current index.php carefully**

Read `public/index.php` (already read above). Identify the insertion points:
- After route matching (line ~205 where `$params` is set)
- The dispatch block (lines ~234-361, the big `match(true)` expression)
- The `sendJson()` helper

**Step 2: Add the pipeline shim**

The changes:

1. Add imports after existing `use` statements:
   ```php
   use Symfony\Component\HttpFoundation\Request as HttpRequest;
   use Symfony\Component\HttpFoundation\JsonResponse;
   use Waaseyaa\Foundation\Middleware\HttpPipeline;
   use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
   use Waaseyaa\User\Middleware\SessionMiddleware;
   use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
   use Waaseyaa\Routing\AccessChecker;
   ```

2. After route matching produces `$params`, before dispatch, create a Request:
   ```php
   $httpRequest = HttpRequest::createFromGlobals();
   ```

3. Get the matched Route object from the router's route collection and set it on the request:
   ```php
   $routeName = $params['_route'] ?? '';
   $matchedRoute = $router->getRouteCollection()->get($routeName);
   if ($matchedRoute !== null) {
       $httpRequest->attributes->set('_route_object', $matchedRoute);
   }
   ```

4. Set `$params` on the request attributes so the final handler can access them:
   ```php
   $httpRequest->attributes->add($params);
   ```

5. Build the pipeline:
   ```php
   $userStorage = $entityTypeManager->getStorage('user');
   $accessChecker = new AccessChecker();
   $pipeline = (new HttpPipeline())
       ->withMiddleware(new SessionMiddleware($userStorage))
       ->withMiddleware(new AuthorizationMiddleware($accessChecker));
   ```

6. Wrap the existing dispatch in a final handler and run the pipeline:
   ```php
   $finalHandler = new class($params, ...) implements HttpHandlerInterface {
       // Move existing match(true) dispatch logic here
       public function handle(HttpRequest $request): \Symfony\Component\HttpFoundation\Response
       {
           // existing dispatch, but return Response objects instead of calling sendJson()
       }
   };

   $response = $pipeline->handle($httpRequest, $finalHandler);
   $response->send();
   ```

**Important:** The existing dispatch uses `sendJson()` which calls `exit`. In the final handler, we need to return `JsonResponse` objects instead. The `sendJson()` helper remains for error cases before the pipeline (CORS, route matching errors).

**Step 3: Test manually**

```bash
php -S localhost:8080 -t public &
curl -s http://localhost:8080/api/entity-types | head -5
# Should return the same JSON as before
kill %1
```

**Step 4: Run all tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: ALL PASS (index.php changes don't affect unit/integration tests)

**Step 5: Commit**

```bash
git add public/index.php
git commit -m "feat: wire authorization pipeline into HTTP front controller"
```

---

### Task 8: Final verification and cleanup

**Step 1: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: ALL PASS

**Step 2: Verify no broken imports or missing dependencies**

Run: `php -l packages/user/src/Middleware/SessionMiddleware.php && php -l packages/access/src/Middleware/AuthorizationMiddleware.php && php -l public/index.php`
Expected: No syntax errors

**Step 3: Commit any final adjustments**

If any fixups were needed, commit them.

**Step 4: Run composer validate on modified packages**

```bash
cd packages/user && composer validate --no-check-all && cd ../..
cd packages/access && composer validate --no-check-all && cd ../..
```

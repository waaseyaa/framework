# ControllerDispatcher Auth Extraction — Phase 1 of #571

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract `auth.login`, `auth.logout`, and `user.me` from ControllerDispatcher into proper controller classes registered via AuthServiceProvider, resolving #1057 (duplicate DatabaseRateLimiter) as a side effect.

**Architecture:** Move login/logout/me logic into `LoginController`, `LogoutController`, and `MeController` in `packages/auth/src/Controller/`, following the existing pattern set by `RegisterController`, `ForgotPasswordController`, etc. These controllers accept `Request` and return `JsonResponse` via `__invoke()`. Routes move from `UserServiceProvider` to `AuthServiceProvider`, changing from string controller keys (e.g., `'auth.login'`) to callable controller objects. ControllerDispatcher already handles callables at line 111: `is_callable($controller)` — it invokes the controller and sends the returned `Response`. This means the new controllers work through the existing dispatch path with zero changes to the dispatch logic. ControllerDispatcher loses three string-matched branches and the `RateLimiterInterface` constructor parameter.

**Tech Stack:** PHP 8.4, Symfony HttpFoundation, PHPUnit 10.5/Pest, existing `RateLimiterInterface`, `AuthController::findUserByName()`

**Issues:** Closes #1057, partially addresses #571

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `packages/auth/src/Controller/LoginController.php` | Validate credentials, rate limit, start session, return user data |
| Create | `packages/auth/src/Controller/LogoutController.php` | Destroy session, return confirmation |
| Create | `packages/auth/src/Controller/MeController.php` | Return current user profile or 401 |
| Create | `packages/auth/tests/Unit/Controller/LoginControllerTest.php` | Unit tests for login (rate limiting, validation, auth) |
| Create | `packages/auth/tests/Unit/Controller/LogoutControllerTest.php` | Unit tests for logout |
| Create | `packages/auth/tests/Unit/Controller/MeControllerTest.php` | Unit tests for me endpoint |
| Modify | `packages/auth/src/AuthServiceProvider.php` | Register login/logout/me routes (moved from UserServiceProvider) |
| Modify | `packages/user/src/UserServiceProvider.php:82-110` | Remove login/logout/me route registrations |
| Modify | `packages/user/tests/Unit/UserServiceProviderTest.php` | Remove assertions for login/logout/me routes |
| Modify | `packages/foundation/src/Http/ControllerDispatcher.php:683-757` | Remove auth.login, auth.logout, user.me branches |
| Modify | `packages/foundation/src/Http/ControllerDispatcher.php:49-66` | Remove `$rateLimiter` constructor param |
| Modify | `packages/foundation/src/Kernel/HttpKernel.php:283-296` | Remove `rateLimiter:` arg, remove DatabaseRateLimiter import |
| Modify | `packages/foundation/tests/Unit/Kernel/HttpKernelTest.php:271-273` | Update route assertions to expect routes still present (now via AuthServiceProvider) |

---

### Task 1: Create LoginController with tests

**Files:**
- Create: `packages/auth/src/Controller/LoginController.php`
- Create: `packages/auth/tests/Unit/Controller/LoginControllerTest.php`

- [ ] **Step 1: Write the failing test for LoginController**

Create `packages/auth/tests/Unit/Controller/LoginControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\User\User;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase
{
    private function createController(
        ?EntityStorageInterface $userStorage = null,
        ?RateLimiter $rateLimiter = null,
    ): LoginController {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());

        if ($userStorage !== null) {
            $entityTypeManager->addEntityType(new \Waaseyaa\Entity\EntityType(
                id: 'user',
                label: 'User',
                class: User::class,
                keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
            ));
        }

        return new LoginController(
            entityTypeManager: $entityTypeManager,
            rateLimiter: $rateLimiter ?? new RateLimiter(),
        );
    }

    #[Test]
    public function rejects_empty_username(): void
    {
        $controller = $this->createController();
        $request = Request::create('/api/auth/login', 'POST', [], [], [], [], json_encode([
            'username' => '',
            'password' => 'secret',
        ]));

        $response = $controller($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function rejects_empty_password(): void
    {
        $controller = $this->createController();
        $request = Request::create('/api/auth/login', 'POST', [], [], [], [], json_encode([
            'username' => 'admin',
            'password' => '',
        ]));

        $response = $controller($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function rejects_missing_body(): void
    {
        $controller = $this->createController();
        $request = Request::create('/api/auth/login', 'POST');

        $response = $controller($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function returns_429_when_rate_limited(): void
    {
        $rateLimiter = new RateLimiter();
        $controller = $this->createController(rateLimiter: $rateLimiter);

        // Exhaust the rate limiter (5 attempts)
        for ($i = 0; $i < 5; $i++) {
            $rateLimiter->hit('login:127.0.0.1', 60);
        }

        $request = Request::create('/api/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ], json_encode(['username' => 'admin', 'password' => 'wrong']));

        $response = $controller($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/auth/tests/Unit/Controller/LoginControllerTest.php`
Expected: FAIL — `LoginController` class not found

- [ ] **Step 3: Write LoginController**

Create `packages/auth/src/Controller/LoginController.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\Http\AuthController;

final class LoginController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly RateLimiterInterface $rateLimiter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $ip = $request->getClientIp() ?? '127.0.0.1';
        $rateLimitKey = 'login:' . $ip;

        if ($this->rateLimiter->tooManyAttempts($rateLimitKey, 5)) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '429', 'title' => 'Too Many Requests', 'detail' => 'Too many login attempts. Please try again later.']],
            ], 429, ['Retry-After' => '60']);
        }

        $body = json_decode((string) $request->getContent(), true) ?? [];
        $username = is_string($body['username'] ?? null) ? trim((string) $body['username']) : '';
        $password = is_string($body['password'] ?? null) ? (string) $body['password'] : '';

        if ($username === '' || $password === '') {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'username and password are required.']],
            ], 400);
        }

        $userStorage = $this->entityTypeManager->getStorage('user');
        $authController = new AuthController();
        $user = $authController->findUserByName($userStorage, $username);

        if ($user === null || !$user->isActive() || !$user->checkPassword($password)) {
            $this->rateLimiter->hit($rateLimitKey, 60);
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '401', 'title' => 'Unauthorized', 'detail' => 'Invalid credentials.']],
            ], 401);
        }

        $this->rateLimiter->clear($rateLimitKey);

        // Session management — these are no-ops in test contexts without active sessions
        if (session_status() === \PHP_SESSION_ACTIVE) {
            $_SESSION['waaseyaa_uid'] = $user->id();
            session_regenerate_id(true);
            session_write_close();
        }

        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'data' => [
                'id' => $user->id(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/auth/tests/Unit/Controller/LoginControllerTest.php`
Expected: All tests pass (the `rejects_*` and `returns_429` tests). The credential-based tests will need a mock storage wired into EntityTypeManager, which may require adjustments to the test helper. Fix any failures before proceeding.

- [ ] **Step 5: Commit**

```bash
git add packages/auth/src/Controller/LoginController.php packages/auth/tests/Unit/Controller/LoginControllerTest.php
git commit -m "feat(#571): add LoginController with rate limiting and validation tests"
```

---

### Task 2: Create LogoutController with tests

**Files:**
- Create: `packages/auth/src/Controller/LogoutController.php`
- Create: `packages/auth/tests/Unit/Controller/LogoutControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/auth/tests/Unit/Controller/LogoutControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\LogoutController;

#[CoversClass(LogoutController::class)]
final class LogoutControllerTest extends TestCase
{
    #[Test]
    public function returns_200_with_logout_message(): void
    {
        $controller = new LogoutController();
        $request = Request::create('/api/auth/logout', 'POST');

        $response = $controller($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Logged out.', $data['meta']['message']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/auth/tests/Unit/Controller/LogoutControllerTest.php`
Expected: FAIL — `LogoutController` class not found

- [ ] **Step 3: Write LogoutController**

Create `packages/auth/src/Controller/LogoutController.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class LogoutController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
            session_regenerate_id(true);
        }

        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'meta' => ['message' => 'Logged out.'],
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/auth/tests/Unit/Controller/LogoutControllerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/auth/src/Controller/LogoutController.php packages/auth/tests/Unit/Controller/LogoutControllerTest.php
git commit -m "feat(#571): add LogoutController"
```

---

### Task 3: Move routes from UserServiceProvider to AuthServiceProvider

**Files:**
- Modify: `packages/auth/src/AuthServiceProvider.php`
- Modify: `packages/user/src/UserServiceProvider.php`
- Modify: `packages/user/tests/Unit/UserServiceProviderTest.php`

- [ ] **Step 1: Remove login/logout/me routes from UserServiceProvider**

In `packages/user/src/UserServiceProvider.php`, delete lines 82-110 (the entire `routes()` method) or just the three route registrations for `api.user.me`, `api.auth.login`, and `api.auth.logout`. If these are the only routes in the method, remove the method entirely (the parent class provides a no-op default).

- [ ] **Step 2: Update UserServiceProviderTest**

In `packages/user/tests/Unit/UserServiceProviderTest.php`, remove assertions on lines 80-82 that check for `api.user.me`, `api.auth.login`, and `api.auth.logout` routes. These routes will now be registered by AuthServiceProvider.

- [ ] **Step 3: Add routes to AuthServiceProvider::routes()**

Add three new route registrations at the end of the `routes()` method in `packages/auth/src/AuthServiceProvider.php`, after the existing `resend_verification` route (line 120). These use callable controller objects instead of string keys:

```php
        $router->addRoute(
            'api.auth.login',
            RouteBuilder::create('/api/auth/login')
                ->controller(new LoginController(
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                    rateLimiter: $rateLimiter,
                ))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.logout',
            RouteBuilder::create('/api/auth/logout')
                ->controller(new LogoutController())
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.user.me',
            RouteBuilder::create('/api/user/me')
                ->controller(new MeController(
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                ))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
```

Add the missing imports at the top of the file:

```php
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Controller\LogoutController;
use Waaseyaa\Auth\Controller\MeController;
```

**Note:** Keep route names (`api.auth.login`, `api.auth.logout`, `api.user.me`) and paths (`/api/auth/login`, `/api/auth/logout`, `/api/user/me`) identical to preserve URL compatibility. The `MeController` doesn't exist yet; Task 4 creates it.

- [ ] **Step 4: Do NOT commit yet**

Continue to Task 4 first — MeController must exist before this compiles.

---

### Task 4: Create MeController

**Files:**
- Create: `packages/auth/src/Controller/MeController.php`
- Create: `packages/auth/tests/Unit/Controller/MeControllerTest.php`

The existing `AuthController::me()` in `packages/user/src/Http/AuthController.php` returns a data array. `MeController` wraps this into a proper `JsonResponse`. We keep `AuthController::findUserByName()` where it is (used by `LoginController`).

- [ ] **Step 1: Write the failing test**

Create `packages/auth/tests/Unit/Controller/MeControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\MeController;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\User;

#[CoversClass(MeController::class)]
final class MeControllerTest extends TestCase
{
    #[Test]
    public function returns_401_for_anonymous_user(): void
    {
        $controller = new MeController(
            entityTypeManager: new EntityTypeManager(new EventDispatcher()),
        );

        $request = Request::create('/api/auth/me');
        $request->attributes->set('_account', new AnonymousUser());

        $response = $controller($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns_200_with_user_data_for_authenticated_user(): void
    {
        $controller = new MeController(
            entityTypeManager: new EntityTypeManager(new EventDispatcher()),
        );

        $user = new User(['uid' => 42, 'name' => 'admin', 'mail' => 'admin@example.com', 'roles' => ['admin']]);
        $request = Request::create('/api/auth/me');
        $request->attributes->set('_account', $user);

        $response = $controller($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(42, $data['data']['id']);
        $this->assertSame('admin', $data['data']['name']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/auth/tests/Unit/Controller/MeControllerTest.php`
Expected: FAIL — `MeController` class not found

- [ ] **Step 3: Write MeController**

Create `packages/auth/src/Controller/MeController.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\Http\AuthController;

final class MeController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $account = $request->attributes->get('_account');
        $authController = new AuthController();
        $result = $authController->me($account);

        $statusCode = $result['statusCode'];
        unset($result['statusCode']);

        return new JsonResponse(
            array_merge(['jsonapi' => ['version' => '1.1']], $result),
            $statusCode,
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/auth/tests/Unit/Controller/MeControllerTest.php`
Expected: PASS

- [ ] **Step 5: Commit Task 3 + Task 4 together**

```bash
git add packages/auth/src/AuthServiceProvider.php packages/auth/src/Controller/MeController.php packages/auth/tests/Unit/Controller/MeControllerTest.php
git commit -m "feat(#571): add MeController and wire login/logout/me routes in AuthServiceProvider"
```

---

### Task 5: Remove auth branches from ControllerDispatcher and clean up HttpKernel

**Files:**
- Modify: `packages/foundation/src/Http/ControllerDispatcher.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`
- Modify: `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php`
- Verify: `packages/foundation/tests/Unit/Kernel/HttpKernelTest.php` (route assertions should still pass since AuthServiceProvider registers the same route names)

- [ ] **Step 1: Remove auth.login branch from ControllerDispatcher**

In `packages/foundation/src/Http/ControllerDispatcher.php`, delete lines 696-744 (the `$controller === 'auth.login'` match arm).

- [ ] **Step 2: Remove auth.logout branch from ControllerDispatcher**

In the same file, delete lines 746-752 (the `$controller === 'auth.logout'` match arm).

- [ ] **Step 3: Remove user.me branch from ControllerDispatcher**

In the same file, delete lines 683-694 (the `$controller === 'user.me'` match arm).

- [ ] **Step 4: Remove RateLimiterInterface from ControllerDispatcher constructor**

In `packages/foundation/src/Http/ControllerDispatcher.php`:
- Remove line 62: `private readonly ?RateLimiterInterface $rateLimiter = null,`
- Remove line 24: `use Waaseyaa\Auth\RateLimiterInterface;`
- Remove the import of `Waaseyaa\User\Http\AuthController` if it was only used by the login branch (line 35)
- Remove the fallback on line 697: `$this->rateLimiter ?? new \Waaseyaa\Auth\RateLimiter()`

- [ ] **Step 5: Remove DatabaseRateLimiter from HttpKernel**

In `packages/foundation/src/Kernel/HttpKernel.php`:
- Remove line 16: `use Waaseyaa\Auth\DatabaseRateLimiter;`
- Remove line 294: `rateLimiter: new DatabaseRateLimiter($this->database),`

- [ ] **Step 6: Update ControllerDispatcherTest if needed**

In `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php`:
- Verify the `createDispatcher()` helper does NOT pass `rateLimiter:`. It currently doesn't (confirmed from reading the file), so no change needed.

- [ ] **Step 7: Run the full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass. The auth routes are now handled by AuthServiceProvider's route registrations, not by ControllerDispatcher.

- [ ] **Step 8: Commit**

```bash
git add packages/foundation/src/Http/ControllerDispatcher.php packages/foundation/src/Kernel/HttpKernel.php
git commit -m "refactor(#571): remove auth.login, auth.logout, user.me from ControllerDispatcher

Closes #1057 — DatabaseRateLimiter is now only instantiated via AuthServiceProvider singleton."
```

---

### Task 6: Verify integration and run code quality checks

**Files:** None (verification only)

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass, no regressions.

- [ ] **Step 2: Run PHPStan**

Run: `composer phpstan`
Expected: No new errors. If PHPStan complains about removed references, fix them.

- [ ] **Step 3: Run code style check**

Run: `composer cs-check`
Expected: No violations. If any, run `composer cs-fix` then re-check.

- [ ] **Step 4: Run spec drift detector**

Run: `tools/drift-detector.sh`
Expected: If `access-control.md` or `infrastructure.md` are flagged as stale, update them to note that login/logout/me are now in AuthServiceProvider.

- [ ] **Step 5: Final commit if any fixups**

```bash
git add -A
git commit -m "chore(#571): fix code style and spec drift from auth extraction"
```

---

## Post-Extraction Metrics

After completing all tasks, verify:

| Metric | Before | After |
|--------|--------|-------|
| ControllerDispatcher lines | ~1,063 | ~980 (removed ~80 lines) |
| ControllerDispatcher constructor params | 12 | 11 (removed rateLimiter) |
| DatabaseRateLimiter instances per request | 2 | 1 (singleton via AuthServiceProvider) |
| Auth controller branches in dispatcher | 3 (login, logout, me) | 0 |
| Auth routes in AuthServiceProvider | 5 | 8 (added login, logout, me) |

## Future Phases

This plan covers Phase 1 (auth extraction). Future phases of #571:

- **Phase 2:** Extract `media.upload` (150 lines + 6 helper methods) into `MediaUploadController`
- **Phase 3:** Extract `broadcast` (70 lines) into `BroadcastController`
- **Phase 4:** Extract `entity_type.enable/disable` into `EntityTypeLifecycleController`
- **Phase 5:** Extract remaining domain routers (discovery, mcp, graphql, openapi) and reduce ControllerDispatcher to a thin delegator

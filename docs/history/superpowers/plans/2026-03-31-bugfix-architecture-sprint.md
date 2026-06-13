# Bugfix & Architecture Sprint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 3 bugs (#800, #797, #801) and clean up 2 architecture issues (#798, #799) in the Waaseyaa framework.

**Architecture:** Lazy service resolution to fix boot ordering; shared `useApi()` composable to centralize admin SPA fetch calls; dead mail interface removal; and broader JSON body parsing in ControllerDispatcher via `Content-Type` detection instead of route-level `_json_api` flags.

**Tech Stack:** PHP 8.4, Nuxt 3/TypeScript, PHPUnit 10.5, Vitest

---

### Task 1: Fix eager MailDriverInterface resolution in UserServiceProvider (#800)

**Files:**
- Modify: `packages/user/src/UserServiceProvider.php:70-75`
- Modify: `packages/user/src/AuthMailer.php:14-18`
- Test: `packages/user/tests/Unit/AuthMailerTest.php`

The `UserServiceProvider::register()` singleton closure calls `$this->resolve(MailDriverInterface::class)` which throws if `MailServiceProvider` hasn't registered yet. Fix: accept a `\Closure` for the driver and resolve lazily on first use.

- [ ] **Step 1: Write the failing test**

Create `packages/user/tests/Unit/AuthMailerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Mail\Driver\NullMailDriver;
use Waaseyaa\Mail\MailDriverInterface;
use Waaseyaa\User\AuthMailer;

#[CoversClass(AuthMailer::class)]
final class AuthMailerTest extends TestCase
{
    #[Test]
    public function it_accepts_closure_driver_and_resolves_lazily(): void
    {
        $resolved = false;
        $driverFactory = function () use (&$resolved): MailDriverInterface {
            $resolved = true;
            return new NullMailDriver();
        };

        $twig = new Environment(new ArrayLoader([]));
        $mailer = new AuthMailer(
            driver: $driverFactory,
            twig: $twig,
            baseUrl: 'http://localhost',
            appName: 'Test',
        );

        // Driver should NOT be resolved yet — lazy
        $this->assertFalse($resolved);

        // Calling isConfigured() triggers resolution
        $mailer->isConfigured();
        $this->assertTrue($resolved);
    }

    #[Test]
    public function it_accepts_concrete_driver_directly(): void
    {
        $driver = new NullMailDriver();
        $twig = new Environment(new ArrayLoader([]));
        $mailer = new AuthMailer(
            driver: $driver,
            twig: $twig,
            baseUrl: 'http://localhost',
            appName: 'Test',
        );

        $this->assertFalse($mailer->isConfigured());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/AuthMailerTest.php`
Expected: FAIL — `AuthMailer` constructor doesn't accept `\Closure` yet.

- [ ] **Step 3: Update AuthMailer to accept lazy driver**

Replace `packages/user/src/AuthMailer.php` constructor and add lazy resolution:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Twig\Environment;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Mail\MailDriverInterface;
use Waaseyaa\Mail\MailMessage;

class AuthMailer
{
    private MailDriverInterface|null $resolvedDriver = null;

    /**
     * @param MailDriverInterface|\Closure(): MailDriverInterface $driver
     */
    public function __construct(
        private readonly MailDriverInterface|\Closure $driver,
        private readonly Environment $twig,
        private readonly string $baseUrl,
        private readonly string $appName,
    ) {}

    private function driver(): MailDriverInterface
    {
        if ($this->resolvedDriver === null) {
            $this->resolvedDriver = $this->driver instanceof \Closure
                ? ($this->driver)()
                : $this->driver;
        }
        return $this->resolvedDriver;
    }

    public function isConfigured(): bool
    {
        return $this->driver()->isConfigured();
    }

    public function sendPasswordReset(FieldableInterface $user, string $token): void
    {
        if (!$this->driver()->isConfigured()) {
            return;
        }

        $vars = [
            'user_name' => $user->get('name'),
            'reset_url' => $this->baseUrl . '/reset-password?token=' . $token,
        ];

        $html = $this->twig->render('email/password-reset.html.twig', $vars);
        $text = $this->twig->render('email/password-reset.txt.twig', $vars);

        $this->driver()->send(new MailMessage(
            from: '',
            to: $user->get('mail'),
            subject: "Reset your {$this->appName} password",
            body: $text,
            htmlBody: $html,
        ));
    }

    public function sendEmailVerification(FieldableInterface $user, string $token): void
    {
        if (!$this->driver()->isConfigured()) {
            return;
        }

        $vars = [
            'user_name' => $user->get('name'),
            'verify_url' => $this->baseUrl . '/verify-email?token=' . $token,
        ];

        $html = $this->twig->render('email/email-verification.html.twig', $vars);
        $text = $this->twig->render('email/email-verification.txt.twig', $vars);

        $this->driver()->send(new MailMessage(
            from: '',
            to: $user->get('mail'),
            subject: "Verify your email for {$this->appName}",
            body: $text,
            htmlBody: $html,
        ));
    }

    public function sendWelcome(FieldableInterface $user): void
    {
        if (!$this->driver()->isConfigured()) {
            return;
        }

        $vars = [
            'user_name' => $user->get('name'),
            'home_url' => $this->baseUrl,
        ];

        $html = $this->twig->render('email/welcome.html.twig', $vars);
        $text = $this->twig->render('email/welcome.txt.twig', $vars);

        $this->driver()->send(new MailMessage(
            from: '',
            to: $user->get('mail'),
            subject: "Welcome to {$this->appName}",
            body: $text,
            htmlBody: $html,
        ));
    }
}
```

- [ ] **Step 4: Update UserServiceProvider to pass closure**

In `packages/user/src/UserServiceProvider.php`, change the AuthMailer singleton to pass a closure for the driver:

```php
$this->singleton(AuthMailer::class, fn() => new AuthMailer(
    driver: fn() => $this->resolve(MailDriverInterface::class),
    twig: \Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment(),
    baseUrl: $config['app']['url'] ?? '',
    appName: $config['app']['name'] ?? 'Waaseyaa',
));
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/AuthMailerTest.php`
Expected: PASS (both tests)

- [ ] **Step 6: Run full test suite to check for regressions**

Run: `./vendor/bin/phpunit`
Expected: No new failures.

- [ ] **Step 7: Commit**

```bash
git add packages/user/src/AuthMailer.php packages/user/src/UserServiceProvider.php packages/user/tests/Unit/AuthMailerTest.php
git commit -m "fix(#800): lazy-resolve MailDriverInterface in AuthMailer to prevent boot failures

AuthMailer now accepts either a concrete MailDriverInterface or a Closure
that returns one. UserServiceProvider passes a closure so the driver is
only resolved when AuthMailer is actually used, eliminating the boot-order
dependency on MailServiceProvider.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Create useApi() composable for admin SPA (#801, #797)

**Files:**
- Create: `packages/admin/app/composables/useApi.ts`
- Test: `packages/admin/tests/unit/composables/useApi.test.ts`

The admin SPA has 15+ `$fetch` calls scattered across composables, plugins, and pages. Some use `baseURL: '/'`, some use nothing. When Nuxt's `app.baseURL` is `/admin/`, calls without `baseURL: '/'` break. A shared `useApi()` composable centralizes the pattern: always `baseURL: '/'` and `credentials: 'include'`.

- [ ] **Step 1: Create the useApi composable**

Create `packages/admin/app/composables/useApi.ts`:

```typescript
import type { FetchOptions } from 'ofetch'

/**
 * Shared API fetch wrapper.
 *
 * All admin SPA calls to /api/* and /_surface/* must go through this
 * to ensure correct baseURL (bypasses Nuxt's app.baseURL prefix) and
 * credentials (sends session cookie).
 */
export function useApi() {
  async function apiFetch<T>(path: string, options: FetchOptions = {}): Promise<T> {
    return $fetch<T>(path, {
      baseURL: '/',
      credentials: 'include',
      ...options,
    })
  }

  return { apiFetch }
}
```

- [ ] **Step 2: Write the unit test**

Create `packages/admin/tests/unit/composables/useApi.test.ts`:

```typescript
import { describe, it, expect, vi } from 'vitest'
import { useApi } from '../../../app/composables/useApi'

// Mock $fetch globally (Nuxt auto-import)
const mockFetch = vi.fn().mockResolvedValue({ data: 'test' })
vi.stubGlobal('$fetch', mockFetch)

describe('useApi', () => {
  it('passes baseURL and credentials by default', async () => {
    const { apiFetch } = useApi()
    await apiFetch('/api/user/me')

    expect(mockFetch).toHaveBeenCalledWith('/api/user/me', {
      baseURL: '/',
      credentials: 'include',
    })
  })

  it('merges caller options without overriding baseURL', async () => {
    const { apiFetch } = useApi()
    await apiFetch('/api/auth/login', {
      method: 'POST',
      body: { username: 'admin', password: 'pass' },
    })

    expect(mockFetch).toHaveBeenCalledWith('/api/auth/login', {
      baseURL: '/',
      credentials: 'include',
      method: 'POST',
      body: { username: 'admin', password: 'pass' },
    })
  })

  it('allows ignoreResponseError passthrough', async () => {
    const { apiFetch } = useApi()
    await apiFetch('/api/test', { ignoreResponseError: true })

    expect(mockFetch).toHaveBeenCalledWith('/api/test', {
      baseURL: '/',
      credentials: 'include',
      ignoreResponseError: true,
    })
  })
})
```

- [ ] **Step 3: Run the test**

Run: `cd packages/admin && npx vitest run tests/unit/composables/useApi.test.ts`
Expected: PASS (all 3 tests)

- [ ] **Step 4: Commit**

```bash
git add packages/admin/app/composables/useApi.ts packages/admin/tests/unit/composables/useApi.test.ts
git commit -m "feat(#801): add useApi() composable for centralized admin SPA fetch calls

Wraps \$fetch with baseURL: '/' and credentials: 'include' to prevent
Nuxt's app.baseURL from prefixing /api/* and /_surface/* paths.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Migrate useAuth.ts to useApi() (#801, #797)

**Files:**
- Modify: `packages/admin/app/composables/useAuth.ts`

All 8 `$fetch` calls in useAuth already use `baseURL: '/'` and `credentials: 'include'` — replace them with `apiFetch` from `useApi()`.

- [ ] **Step 1: Replace all $fetch calls in useAuth.ts**

At the top of the `useAuth()` function, add:
```typescript
const { apiFetch } = useApi()
```

Then replace every `$fetch<...>('/api/...', { baseURL: '/', credentials: 'include', ...opts })` with `apiFetch<...>('/api/...', { ...opts })`.

The 8 call sites to change:
1. Line 22: `checkAuth` — `$fetch('/api/user/me', ...)` → `apiFetch('/api/user/me', { ignoreResponseError: true })`
2. Line 36: `login` — `$fetch('/api/auth/login', ...)` → `apiFetch('/api/auth/login', { method: 'POST', body: { username, password }, ignoreResponseError: true })`
3. Line 73: `register` — same pattern
4. Line 105: `forgotPassword` — same pattern
5. Line 133: `resetPassword` — same pattern
6. Line 157: `verifyEmail` — same pattern
7. Line 184: `resendVerification` — same pattern
8. Line 206: `logout` — same pattern

- [ ] **Step 2: Run existing tests**

Run: `cd packages/admin && npx vitest run`
Expected: PASS — no behavioral change, just centralized fetch config.

- [ ] **Step 3: Run TypeScript build check**

Run: `cd packages/admin && npx nuxi typecheck`
Expected: No new type errors.

- [ ] **Step 4: Commit**

```bash
git add packages/admin/app/composables/useAuth.ts
git commit -m "refactor(#797): migrate useAuth.ts to useApi() composable

Replaces 8 raw \$fetch calls with apiFetch(), centralizing baseURL and
credentials configuration. Fixes session persistence by ensuring all
auth endpoints use consistent fetch options.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Migrate remaining admin SPA $fetch calls to useApi() (#801)

**Files:**
- Modify: `packages/admin/app/plugins/admin.ts:56,64`
- Modify: `packages/admin/app/composables/useCodifiedContext.ts:46,61,76,91`
- Modify: `packages/admin/app/pages/[entityType]/index.vue:46,62`

These files have `$fetch` calls to `/api/*` or `/_surface/*` without `baseURL: '/'` — they break when `app.baseURL` is set.

- [ ] **Step 1: Fix admin.ts plugin**

The plugin uses `defineNuxtPlugin(async () => ...)` — it cannot call composables directly. Instead, import `$fetch` with the correct options inline. Change lines 56 and 64:

```typescript
// Line 56 — replace:
const sessionRes = await $fetch<SurfaceResult<SurfaceSession>>(`${surfacePath}/session`, {
  ignoreResponseError: true,
  credentials: 'include',
})
// With:
const sessionRes = await $fetch<SurfaceResult<SurfaceSession>>(`${surfacePath}/session`, {
  baseURL: '/',
  ignoreResponseError: true,
  credentials: 'include',
})

// Line 64 — replace:
const catalogRes = await $fetch<SurfaceResult<{ entities: SurfaceCatalogEntry[] }>>(`${surfacePath}/catalog`, {
  ignoreResponseError: true,
  credentials: 'include',
})
// With:
const catalogRes = await $fetch<SurfaceResult<{ entities: SurfaceCatalogEntry[] }>>(`${surfacePath}/catalog`, {
  baseURL: '/',
  ignoreResponseError: true,
  credentials: 'include',
})
```

- [ ] **Step 2: Fix useCodifiedContext.ts**

Add `const { apiFetch } = useApi()` at the top of `useCodifiedContext()`, then replace all 4 `$fetch` calls:

```typescript
// fetchSessions (line 46):
const response = await apiFetch<{ data: CodifiedContextSession[] }>(
  `/api/telescope/codified-context/sessions?limit=${limit}`,
)

// fetchSession (line 61):
const response = await apiFetch<{ data: CodifiedContextSession }>(
  `/api/telescope/codified-context/sessions/${id}`,
)

// fetchEvents (line 76):
const response = await apiFetch<{ data: CodifiedContextEvent[] }>(
  `/api/telescope/codified-context/sessions/${id}/events`,
)

// fetchValidation (line 91):
const response = await apiFetch<{ data: ValidationReport }>(
  `/api/telescope/codified-context/sessions/${id}/validation`,
)
```

- [ ] **Step 3: Fix [entityType]/index.vue**

Add `const { apiFetch } = useApi()` in the `<script setup>` block, then replace lines 46 and 62:

```typescript
// Line 46:
await apiFetch(`/api/entity-types/${entityType.value}/disable${query}`, { method: 'POST' })

// Line 62:
await apiFetch(`/api/entity-types/${entityType.value}/enable`, { method: 'POST' })
```

- [ ] **Step 4: Run tests and build check**

Run: `cd packages/admin && npx vitest run && npx nuxi typecheck`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/plugins/admin.ts packages/admin/app/composables/useCodifiedContext.ts packages/admin/app/pages/\[entityType\]/index.vue
git commit -m "fix(#801): add baseURL to all remaining admin SPA fetch calls

Migrates useCodifiedContext and [entityType]/index.vue to useApi().
Adds baseURL: '/' to admin.ts plugin fetch calls (plugins can't use
composables). All /api/* and /_surface/* calls now work correctly
regardless of Nuxt app.baseURL setting.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Remove dead MailerInterface and Mailer class (#798)

**Files:**
- Delete: `packages/mail/src/MailerInterface.php`
- Delete: `packages/mail/src/Mailer.php`
- Delete: `packages/mail/src/Envelope.php`
- Delete: `packages/mail/tests/Unit/MailerTest.php`
- Delete: `packages/mail/tests/Unit/EnvelopeTest.php`
- Modify: `packages/mail/src/MailServiceProvider.php:32-35`

`MailerInterface`, `Mailer`, and `Envelope` are completely unused — zero consumers outside their own test files. `MailDriverInterface` is the real mail abstraction used by `AuthMailer`. The `Transport` layer (`TransportInterface`, `ArrayTransport`, `LocalTransport`) is only consumed by `Mailer`, so it is also dead code.

- [ ] **Step 1: Verify no consumers exist**

Run these greps to confirm nothing uses the dead code:

```bash
# Should return only MailServiceProvider, Mailer.php, and test files:
grep -rn 'MailerInterface' packages/ --include='*.php' | grep -v 'test' | grep -v 'Test'

# Should return only Mailer.php and MailServiceProvider:
grep -rn 'use Waaseyaa\\Mail\\Mailer;' packages/ --include='*.php'

# Should return only Mailer.php and Envelope test:
grep -rn 'use Waaseyaa\\Mail\\Envelope;' packages/ --include='*.php'

# Transport consumers — should be only Mailer.php, MailServiceProvider, and tests:
grep -rn 'TransportInterface\|ArrayTransport\|LocalTransport' packages/ --include='*.php' | grep -v 'test' | grep -v 'Test'
```

If any unexpected consumers appear, stop and reassess.

- [ ] **Step 2: Remove MailerInterface singleton from MailServiceProvider**

In `packages/mail/src/MailServiceProvider.php`, remove lines 22-35 (TransportInterface and MailerInterface singletons) and their imports. The file should become:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mail\Driver\NullMailDriver;
use Waaseyaa\Mail\Driver\SendGridDriver;

final class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $mailConfig = $this->config['mail'] ?? [];
        $fromAddress = $mailConfig['from_address'] ?? '';
        $sendgridKey = $mailConfig['sendgrid_api_key'] ?? '';
        $fromName = $mailConfig['from_name'] ?? '';

        $this->singleton(MailDriverInterface::class, fn(): MailDriverInterface => match (true) {
            $sendgridKey !== '' => new SendGridDriver($sendgridKey, $fromAddress, $fromName),
            default => new NullMailDriver(),
        });
    }
}
```

- [ ] **Step 3: Delete dead files**

```bash
rm packages/mail/src/MailerInterface.php
rm packages/mail/src/Mailer.php
rm packages/mail/src/Envelope.php
rm packages/mail/src/Transport/TransportInterface.php
rm packages/mail/src/Transport/ArrayTransport.php
rm packages/mail/src/Transport/LocalTransport.php
rm packages/mail/tests/Unit/MailerTest.php
rm packages/mail/tests/Unit/EnvelopeTest.php
rm packages/mail/tests/Unit/Transport/ArrayTransportTest.php
rm packages/mail/tests/Unit/Transport/LocalTransportTest.php
rmdir packages/mail/src/Transport 2>/dev/null || true
rmdir packages/mail/tests/Unit/Transport 2>/dev/null || true
```

- [ ] **Step 4: Run remaining mail tests**

Run: `./vendor/bin/phpunit packages/mail/tests/`
Expected: PASS — `MailServiceProviderTest`, `SendGridDriverTest`, `MailMessageTest`, `TwigMailRendererTest` should still pass.

- [ ] **Step 5: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: No new failures (nothing was using the deleted code).

- [ ] **Step 6: Commit**

```bash
git add -A packages/mail/
git commit -m "refactor(#798): remove unused MailerInterface, Mailer, Envelope, and Transport layer

MailDriverInterface is the sole mail abstraction (used by AuthMailer).
The MailerInterface/Mailer/Envelope/Transport stack had zero consumers
outside its own tests. Removes 7 dead files and simplifies MailServiceProvider.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Broaden JSON body parsing in ControllerDispatcher (#799)

**Files:**
- Modify: `packages/foundation/src/Http/ControllerDispatcher.php:83-100,707-708`

Currently only routes with `_json_api: true` get auto-parsed JSON bodies. Routes like `auth.login` do manual `getContent()` + `json_decode()`. Fix: parse JSON body for any POST/PATCH/PUT/DELETE request with `Content-Type: application/json` header, regardless of route options. Keep `_json_api` as an opt-in override for routes that want JSON parsing even without the header.

- [ ] **Step 1: Update the JSON body parsing block**

In `packages/foundation/src/Http/ControllerDispatcher.php`, replace lines 83-100 (the `$body` parsing block):

```php
// Parse JSON body for requests that send JSON content.
// Routes with _json_api option always get JSON parsing (backward-compat).
// Other routes get JSON parsing when Content-Type is application/json.
$body = null;
$matchedRoute = $httpRequest->attributes->get('_route_object');
$isJsonApi = $matchedRoute !== null && $matchedRoute->getOption('_json_api') === true;
$isJsonContent = str_starts_with(
    (string) $httpRequest->headers->get('Content-Type', ''),
    'application/json',
);
if (($isJsonApi || $isJsonContent) && in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
    $raw = $httpRequest->getContent();
    if ($raw !== '') {
        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            ResponseSender::json(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Invalid JSON in request body.']]]);
        }
    } else {
        $body = [];
    }
}
```

- [ ] **Step 2: Remove manual JSON parsing from auth.login handler**

In `packages/foundation/src/Http/ControllerDispatcher.php`, replace lines 707-708:

```php
// Before:
$raw = $httpRequest->getContent();
$safeBody = ($raw !== '') ? (json_decode($raw, true) ?? []) : [];

// After (use the already-parsed $body):
$safeBody = $body ?? [];
```

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: PASS — existing `_json_api` routes are unaffected. Auth login now uses the centralized parser.

- [ ] **Step 4: Commit**

```bash
git add packages/foundation/src/Http/ControllerDispatcher.php
git commit -m "fix(#799): parse JSON body for all application/json POST/PATCH/PUT/DELETE requests

ControllerDispatcher now detects Content-Type: application/json in
addition to the _json_api route option. Removes manual json_decode
from auth.login handler since the centralized parser now handles it.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Final verification and code style

- [ ] **Step 1: Run code style check**

Run: `composer cs-check`
If violations found: `composer cs-fix`

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: No new errors.

- [ ] **Step 3: Run full test suite one final time**

Run: `./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 4: Run admin SPA build check**

Run: `cd packages/admin && npm run build`
Expected: Build succeeds.

- [ ] **Step 5: Fix any issues found, commit if needed**

```bash
git add -A
git commit -m "chore: fix code style and static analysis issues

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

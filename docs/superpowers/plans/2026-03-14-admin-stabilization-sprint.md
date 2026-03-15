# Admin Stabilization Sprint Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the 5 admin smoke test issues (#406–#410) so the admin SPA boots end-to-end in dev mode.

**Architecture:** Single combined PR on `develop/v1.1`. Backend DI fix in SSR package, frontend fixes in admin package, docs updates at root. Fix order follows the dependency chain: #407 → #408 → #406 → #409 → #410.

**Tech Stack:** PHP 8.3+ (SSR, Foundation), Nuxt 3 / Vue 3 / TypeScript (admin SPA), PHPUnit 10.5, Vitest.

**Spec:** `docs/superpowers/specs/2026-03-14-admin-stabilization-sprint-design.md`

---

## Chunk 1: Backend DI Resolution (#407)

### Task 1: Add serviceResolver to SsrPageHandler

**Files:**
- Modify: `packages/foundation/src/ServiceProvider/ServiceProvider.php:90` (make `resolve()` public)
- Modify: `packages/ssr/src/SsrPageHandler.php:37-47` (constructor) and `:328-341` (fallback branch)
- Create: `packages/ssr/tests/Unit/SsrPageHandlerResolverTest.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php:101-110` (wire resolver)

- [ ] **Step 0: Make ServiceProvider::resolve() public**

In `packages/foundation/src/ServiceProvider/ServiceProvider.php`, change line 90 from:

```php
    protected function resolve(string $abstract): mixed
```

to:

```php
    public function resolve(string $abstract): mixed
```

**Why:** The resolver closure in `HttpKernel` (Step 6) needs to call `$provider->resolve()` from outside the class hierarchy. Currently `protected`, which would cause a fatal error at runtime.

- [ ] **Step 1: Write the failing test for serviceResolver fallback**

Create `packages/ssr/tests/Unit/SsrPageHandlerResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Cache\CacheConfigResolver;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrPageHandler;

// Stub controller with a custom dependency
class StubDependency
{
    public string $value = 'resolved';
}

class StubController
{
    public function __construct(
        public readonly StubDependency $dep,
    ) {}
}

class StubControllerWithDefault
{
    public function __construct(
        public readonly ?StubDependency $dep = null,
    ) {}
}

#[CoversClass(SsrPageHandler::class)]
final class SsrPageHandlerResolverTest extends TestCase
{
    private function createHandler(?\Closure $serviceResolver = null): SsrPageHandler
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $database = PdoDatabase::createSqlite();
        $discoveryHandler = new DiscoveryApiHandler($entityTypeManager, $database);
        $cacheConfigResolver = new CacheConfigResolver([]);

        return new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: $cacheConfigResolver,
            discoveryHandler: $discoveryHandler,
            projectRoot: '/tmp',
            config: [],
            manifest: null,
            serviceResolver: $serviceResolver,
        );
    }

    #[Test]
    public function resolves_custom_dependency_via_service_resolver(): void
    {
        $dep = new StubDependency();
        $resolver = function (string $className) use ($dep): ?object {
            return $className === StubDependency::class ? $dep : null;
        };

        $handler = $this->createHandler($resolver);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $controller = $handler->resolveControllerInstance(
            StubController::class,
            $twig,
            $account,
            $request,
        );

        $this->assertInstanceOf(StubController::class, $controller);
        $this->assertSame($dep, $controller->dep);
    }

    #[Test]
    public function falls_back_to_default_when_resolver_returns_null(): void
    {
        $resolver = fn (string $className): ?object => null;

        $handler = $this->createHandler($resolver);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $controller = $handler->resolveControllerInstance(
            StubControllerWithDefault::class,
            $twig,
            $account,
            $request,
        );

        $this->assertInstanceOf(StubControllerWithDefault::class, $controller);
        $this->assertNull($controller->dep);
    }

    #[Test]
    public function works_without_service_resolver(): void
    {
        $handler = $this->createHandler(null);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $controller = $handler->resolveControllerInstance(
            StubControllerWithDefault::class,
            $twig,
            $account,
            $request,
        );

        $this->assertInstanceOf(StubControllerWithDefault::class, $controller);
        $this->assertNull($controller->dep);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/ssr/tests/Unit/SsrPageHandlerResolverTest.php`
Expected: FAIL — `serviceResolver` parameter does not exist yet.

- [ ] **Step 3: Add serviceResolver parameter to SsrPageHandler constructor**

In `packages/ssr/src/SsrPageHandler.php`, modify the constructor (line 37-47) to add the new parameter:

```php
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly PdoDatabase $database,
        private readonly ?RenderCache $renderCache,
        private readonly CacheConfigResolver $cacheConfigResolver,
        private readonly DiscoveryApiHandler $discoveryHandler,
        private readonly string $projectRoot,
        /** @var array<string, mixed> */
        private readonly array $config,
        private readonly ?object $manifest = null,
        /** @var (\Closure(string): ?object)|null */
        private readonly ?\Closure $serviceResolver = null,
    ) {}
```

- [ ] **Step 4: Add serviceResolver fallback branch in resolveControllerInstance**

In `packages/ssr/src/SsrPageHandler.php`, replace the `if (!$matched)` block (lines 328-342) with:

```php
            if (!$matched && $type instanceof \ReflectionNamedType && !$type->isBuiltin() && $this->serviceResolver !== null) {
                $resolved = ($this->serviceResolver)($type->getName());
                if ($resolved !== null) {
                    $args[] = $resolved;
                    $matched = true;
                }
            }

            if (!$matched) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } elseif ($param->allowsNull()) {
                    $args[] = null;
                } else {
                    error_log(sprintf(
                        '[Waaseyaa] Cannot resolve constructor parameter $%s (%s) for controller %s',
                        $param->getName(),
                        $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed',
                        $class,
                    ));
                    $args[] = null;
                }
            }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/ssr/tests/Unit/SsrPageHandlerResolverTest.php`
Expected: 3 tests, 3 assertions, all PASS.

- [ ] **Step 6: Wire the resolver in HttpKernel**

In `packages/foundation/src/Kernel/HttpKernel.php`, modify the `SsrPageHandler` construction (lines 101-110) to pass a resolver closure:

```php
        $this->ssrPageHandler = new SsrPageHandler(
            entityTypeManager: $this->entityTypeManager,
            database: $this->database,
            renderCache: $this->renderCache,
            cacheConfigResolver: $this->cacheConfigResolver,
            discoveryHandler: $this->discoveryHandler,
            projectRoot: $this->projectRoot,
            config: $this->config,
            manifest: $this->manifest,
            serviceResolver: function (string $className): ?object {
                foreach ($this->providers as $provider) {
                    if (isset($provider->getBindings()[$className])) {
                        return $provider->resolve($className);
                    }
                }
                return null;
            },
        );
```

- [ ] **Step 7: Run full test suite to check for regressions**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All existing tests pass.

- [ ] **Step 8: Commit**

```bash
git add packages/foundation/src/ServiceProvider/ServiceProvider.php \
       packages/ssr/src/SsrPageHandler.php \
       packages/ssr/tests/Unit/SsrPageHandlerResolverTest.php \
       packages/foundation/src/Kernel/HttpKernel.php
git commit -m "fix(#407): add serviceResolver fallback to SsrPageHandler DI"
```

---

## Chunk 2: Frontend Fixes (#408, #406, #409)

### Task 2: Fix bootstrap URL and add proxy rule (#408)

**Files:**
- Modify: `packages/admin/app/plugins/admin.ts:17` (URL path)
- Modify: `packages/admin/nuxt.config.ts:10-12` (proxy rule)

- [ ] **Step 1: Fix the fetch URL in admin.ts**

In `packages/admin/app/plugins/admin.ts`, change line 17 from:

```typescript
    const response = await $fetch<AdminBootstrap>(`${baseUrl}/bootstrap`, {
```

to:

```typescript
    const response = await $fetch<AdminBootstrap>(`${baseUrl}/admin/bootstrap`, {
```

- [ ] **Step 2: Add proxy rule for /admin/** in nuxt.config.ts**

In `packages/admin/nuxt.config.ts`, change the `routeRules` block (lines 10-12) from:

```typescript
  routeRules: {
    '/api/**': { proxy: 'http://localhost:8081/api/**' },
  },
```

to:

```typescript
  routeRules: {
    '/api/**': { proxy: 'http://localhost:8081/api/**' },
    '/admin/**': { proxy: 'http://localhost:8081/admin/**' },
  },
```

- [ ] **Step 3: Commit**

```bash
git add packages/admin/app/plugins/admin.ts packages/admin/nuxt.config.ts
git commit -m "fix(#408): correct bootstrap URL to /admin/bootstrap and add proxy rule"
```

### Task 3: Disable SSR and remove viteEnvironmentApi (#406)

**Files:**
- Modify: `packages/admin/nuxt.config.ts:1-7` (add ssr: false, remove experimental block)

- [ ] **Step 1: Add ssr: false and remove experimental block**

In `packages/admin/nuxt.config.ts`, remove the `experimental` block and add `ssr: false`. After Task 2's proxy rule addition, the file's top will look like this — find and replace accordingly:

Remove these lines:
```typescript
  experimental: {
    viteEnvironmentApi: true,
  },
```

Add `ssr: false,` after `devtools: { enabled: true },` so the top of the file reads:

```typescript
export default defineNuxtConfig({
  compatibilityDate: '2025-01-01',
  devtools: { enabled: true },
  ssr: false,

  srcDir: 'app/',
```

- [ ] **Step 2: Verify build still works**

Run: `cd packages/admin && npm run build`
Expected: Build succeeds with no errors.

- [ ] **Step 3: Commit**

```bash
git add packages/admin/nuxt.config.ts
git commit -m "fix(#406): disable SSR and remove viteEnvironmentApi experimental flag"
```

### Task 4: Remove i18n dependency from error.vue (#409)

**Files:**
- Modify: `packages/admin/app/error.vue:1-17` (script) and `:19-31` (template)

- [ ] **Step 1: Replace script setup block**

In `packages/admin/app/error.vue`, replace the entire `<script setup>` block (lines 1-17) with:

```vue
<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{
  error: NuxtError
}>()

const messages: Record<string, string> = {
  error_page_title: 'Error',
  error_not_found: 'Page not found',
  error_generic: 'Something went wrong',
  error_page_back: 'Go back',
}
const t = (key: string) => messages[key] ?? key

useHead({
  title: t('error_page_title'),
})

const message = computed(() =>
  props.error.statusCode === 404 ? t('error_not_found') : t('error_generic'),
)
</script>
```

- [ ] **Step 2: Replace $t() calls in template**

In `packages/admin/app/error.vue`, replace the template block (lines 19-31) with:

```vue
<template>
  <div class="error-page">
    <div class="error-card">
      <div class="error-icon" aria-hidden="true">
        {{ error.statusCode === 404 ? '404' : '!' }}
      </div>
      <h1 class="error-title">{{ t('error_page_title') }}</h1>
      <p class="error-message">{{ message }}</p>
      <NuxtLink to="/" class="error-back">
        {{ t('error_page_back') }}
      </NuxtLink>
    </div>
  </div>
</template>
```

- [ ] **Step 3: Verify no $t or useI18n references remain**

Run: `grep -n 'useI18n\|\$t(' packages/admin/app/error.vue`
Expected: No output (no matches).

- [ ] **Step 4: Run frontend tests**

Run: `cd packages/admin && npm test`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/error.vue
git commit -m "fix(#409): remove i18n dependency from error.vue, use hardcoded strings"
```

---

## Chunk 3: Documentation & Verification (#410)

### Task 5: Update env var documentation and dev script (#410)

**Files:**
- Modify: `.env.example:9-14` and `:34-45` (add Local Admin Development section)
- Modify: `composer.json:141-144` (dev script)

- [ ] **Step 1: Add Local Admin Development section to .env.example**

In `.env.example`, replace lines 9-14 (Application section) with:

```env
# ── Application ────────────────────────────────────────────────────────────────
# Controls debug mode, error display, and dev-only helpers.
# Values: local | staging | production
# Default: production
#
APP_ENV=local

# ── Local Admin Development ───────────────────────────────────────────────────
# To run the admin SPA locally, THREE conditions must be met:
#
#   1. PHP SAPI = cli-server  (automatic when using `composer dev`)
#   2. APP_ENV = local|dev|development  (set above)
#   3. WAASEYAA_DEV_FALLBACK_ACCOUNT = true  (set below)
#
# All three are required. Missing any one results in silent 401 Unauthorized
# responses on protected admin endpoints. The `composer dev` script sets
# conditions 1-3 automatically.
#
# SECURITY: Never enable dev fallback account in staging or production.
```

- [ ] **Step 2: Move WAASEYAA_DEV_FALLBACK_ACCOUNT under the new section**

In `.env.example`, replace lines 41-45 (the old dev fallback section under Authentication) with:

```env
# Dev-only fallback: automatically authenticates every request as a platform admin.
# Required for local admin SPA development (see "Local Admin Development" above).
# MUST remain false (or unset) in staging and production.
# Default: false
#
WAASEYAA_DEV_FALLBACK_ACCOUNT=true
```

Note: Set to `true` in the example since `APP_ENV=local` is also set — this makes copy-and-go work for new developers.

- [ ] **Step 3: Update composer.json dev script**

In `composer.json`, change line 143 from:

```json
            "PHP_CLI_SERVER_WORKERS=4 php -S localhost:8081 -t public & npm run dev --prefix packages/admin && kill $!"
```

to:

```json
            "APP_ENV=local WAASEYAA_DEV_FALLBACK_ACCOUNT=true PHP_CLI_SERVER_WORKERS=4 php -S localhost:8081 -t public & npm run dev --prefix packages/admin && kill $!"
```

- [ ] **Step 4: Commit**

```bash
git add .env.example composer.json
git commit -m "docs(#410): document local admin dev env vars, update composer dev script"
```

### Task 6: Verification smoke test

- [ ] **Step 1: Run full PHP test suite**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All tests pass (including the new SsrPageHandlerResolverTest).

- [ ] **Step 2: Run frontend tests**

Run: `cd packages/admin && npm test`
Expected: All tests pass.

- [ ] **Step 3: Verify frontend build**

Run: `cd packages/admin && npm run build`
Expected: Build succeeds.

- [ ] **Step 4: Verify no useI18n in error.vue**

Run: `grep -c 'useI18n\|\\$t(' packages/admin/app/error.vue`
Expected: `0`

- [ ] **Step 5: Verify nuxt.config.ts has ssr: false and proxy rules**

Run: `grep -E 'ssr:|/admin/\*\*' packages/admin/nuxt.config.ts`
Expected: Both `ssr: false` and `'/admin/**'` appear.

- [ ] **Step 6: Verify composer dev script includes env vars**

Run: `grep 'APP_ENV=local WAASEYAA_DEV_FALLBACK_ACCOUNT=true' composer.json`
Expected: Match found.

- [ ] **Step 7: Write verification report**

Create a brief verification report summarizing pass/fail for each check. If all pass, the sprint is complete and ready for PR.

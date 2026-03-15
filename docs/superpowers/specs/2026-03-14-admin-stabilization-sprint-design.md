# Admin Stabilization Sprint Design

**Date:** 2026-03-14
**Issues:** #406, #407, #408, #409, #410
**Branch:** `develop/v1.1`
**PR Strategy:** Single combined PR with one commit per issue

## Problem Statement

The 2026-03-14 admin smoke test revealed five tightly coupled issues that prevent the admin SPA from functioning in dev mode. The issues cascade: SSR hangs on first request (#406), the bootstrap URL is wrong (#408), the backend can't resolve controller dependencies (#407), error recovery crashes (#409), and none of this is documented (#410).

## Dependency Map

```
#407 SsrPageHandler DI ──► Backend can resolve AdminBootstrapController
         │
#408 Bootstrap URL ──────► Plugin fetches correct /admin/bootstrap path
         │
#406 SSR disabled ───────► Nuxt runs as SPA, no request starvation
         │
#409 error.vue guards ──► Error page renders without i18n plugin
         │
#410 Env var docs ───────► Developer can set up local admin from .env.example
```

Fix order: **#407 → #408 → #406 → #409 → #410**

## Phase 1: Backend DI Resolution (#407)

**File:** `packages/ssr/src/SsrPageHandler.php`

`resolveControllerInstance()` already uses reflection to iterate constructor parameters and resolve them against a 4-service `$serviceMap` (EntityTypeManager, Twig, HttpRequest, AccountInterface), falling back to default values or null for unmatched types. The missing piece is a general container-delegate fallback.

**Change:** Add an optional `?\Closure $serviceResolver = null` constructor parameter to `SsrPageHandler`. In `resolveControllerInstance()`, insert `($this->serviceResolver)($typeName)` as a fallback between the `$serviceMap` lookup and the existing default/null fallback. No rewrite of the method — a single `elseif` branch.

The resolver is wired where `SsrPageHandler` is constructed (in the kernel or service provider) by passing a closure that queries the service container's bindings.

**Acceptance criteria:**
- `AdminBootstrapController` resolves CatalogBuilder, AdminAuthConfig, AdminTransportConfig, AdminTenant via the resolver
- Existing controllers with standard deps still work unchanged (existing `$serviceMap` is untouched)
- No changes to the existing 4-service map (backward compatible)

## Phase 2: Dev-mode Bootstrap URL (#408)

**File:** `packages/admin/app/plugins/admin.ts`

Change the fetch path from `${baseUrl}/bootstrap` to `${baseUrl}/admin/bootstrap`. The `baseUrl` defaults to empty string in dev mode, producing `/admin/bootstrap` which matches the PHP route registered in `AdminBridgeServiceProvider`.

In production, `window.__WAASEYAA_ADMIN__` inline data bypasses the fetch entirely.

**Required:** Add `'/admin/**': { proxy: 'http://localhost:8081/admin/**' }` to `routeRules` in `nuxt.config.ts`. Without this proxy rule, client-side `$fetch('/admin/bootstrap')` hits the Nuxt dev server (port 3000) instead of the PHP backend (port 8081) and returns 404.

**Acceptance criteria:**
- Dev mode: plugin fetches `/admin/bootstrap` successfully (proxied to PHP backend)
- Production: still uses `window.__WAASEYAA_ADMIN__` inline, fetch not triggered
- `nuxt.config.ts` has proxy rule for `/admin/**`

## Phase 3: SSR Disabled (#406)

**File:** `packages/admin/nuxt.config.ts`

The config has no `ssr:` key, so Nuxt 3 defaults to SSR-on. Add `ssr: false` explicitly. The admin SPA is client-side by design — bootstrap data comes from the PHP backend via API or inline injection. SSR adds no value and causes request starvation with PHP's single-threaded built-in server.

Remove `experimental: { viteEnvironmentApi: true }` — this flag enables Vite's experimental environment API for SSR module isolation. With SSR disabled, it serves no purpose and adds risk surface.

**Acceptance criteria:**
- `nuxt.config.ts` has explicit `ssr: false`
- `experimental.viteEnvironmentApi` removed
- `nuxt dev` starts and serves the SPA on port 3000 without hanging
- `nuxt build` produces a working SPA bundle

## Phase 4: error.vue i18n Resilience (#409)

**File:** `packages/admin/app/error.vue`

Remove `useI18n()` dependency entirely. Replace with hardcoded English string map:

```typescript
const messages: Record<string, string> = {
  error_page_title: 'Error',
  error_not_found: 'Page not found',
  error_generic: 'Something went wrong',
  error_page_back: 'Go back',
}
const t = (key: string) => messages[key] ?? key
```

**Important:** The template also uses `$t('...')` global helpers (injected by the i18n plugin). These must also be replaced with the local `t()` function (e.g., `$t('error_page_title')` → `t('error_page_title')`). Both the script `useI18n()` and template `$t()` calls must be eliminated.

Error pages render outside the normal app lifecycle and must not depend on plugins.

**Acceptance criteria:**
- error.vue renders correctly even when no plugins have loaded
- Error page shows meaningful text for 404 and generic errors
- No `useI18n()` call in script, no `$t()` calls in template

## Phase 5: Environment Documentation (#410)

**Files:** `.env.example`, `composer.json`

The `DevAdminAccount` guard lives in `HttpKernel::shouldUseDevFallbackAccount()` (not `index.php`). It requires three conditions: `PHP_SAPI === 'cli-server'`, `APP_ENV` in `[dev, development, local]`, and `config['auth']['dev_fallback_account'] === true` (mapped from `WAASEYAA_DEV_FALLBACK_ACCOUNT` env var via `config/waaseyaa.php`).

1. Update `.env.example` with a "Local Admin Development" section grouping `APP_ENV` and `WAASEYAA_DEV_FALLBACK_ACCOUNT` together, explaining all three conditions (SAPI is automatic with `composer dev`)
2. Update `composer.json` `dev` script to set `APP_ENV=local WAASEYAA_DEV_FALLBACK_ACCOUNT=true` in the PHP server command so it works out of the box

**Acceptance criteria:**
- `.env.example` has a clear section explaining local admin dev setup with all three conditions
- `composer dev` works out of the box without manual env var setup
- A new developer can follow `.env.example` to get the admin UI running

## Phase 6: Verification

Smoke test sequence validating the full chain:

1. `composer dev` starts PHP + Nuxt without manual env vars
2. Nuxt dev server responds on port 3000 without hanging (SPA mode)
3. `GET /admin/bootstrap` returns valid JSON (not 404)
4. `AdminBootstrapController` resolves all dependencies (not 500)
5. Triggering an error renders error.vue with hardcoded text (no blank page)
6. `.env.example` documents all required vars in a grouped section

**Deliverable:** Verification report documenting each step's pass/fail.

## Constraints

- No changes to unrelated backend packages
- No breaking API changes unless required and documented
- All fixes validated via admin smoke test
- All work lands on `develop/v1.1`

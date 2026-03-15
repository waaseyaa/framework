# Admin UI Smoke Test Report — 2026-03-14

**Environment:** WSL2 Linux, PHP 8.4.18, Nuxt 3.21.1, Node via `npm run dev`
**Database:** Production backup from `minoo.live` (2026-03-14)
**Branch:** `develop/v1.1`

## Summary

The admin SPA is **completely non-functional in local development**. A cascade of 5 blocking issues prevents the app from loading. No UI flows could be tested because the app crashes during plugin initialization.

## Blocking Issues (P0)

### 1. Nuxt SSR hangs indefinitely on first request

**Severity:** P0 — blocks all testing with SSR enabled
**Location:** `packages/admin/nuxt.config.ts` (experimental viteEnvironmentApi)
**Symptoms:** TCP connections to port 3000 succeed but HTTP responses never arrive. `wget` reports "Read error (Connection timed out) in headers." No errors in Nuxt logs.
**Root cause:** Unknown — possibly related to `experimental: { viteEnvironmentApi: true }` or SSR trying to proxy to the PHP backend during server-side rendering. Disabling SSR (`ssr: false`) fixes the issue.
**Workaround:** Add `ssr: false` to `nuxt.config.ts` for dev mode.

### 2. AdminBootstrapController dependencies not resolvable

**Severity:** P0 — blocks admin SPA initialization
**Location:** `packages/admin-bridge/src/AdminBridgeServiceProvider.php`, `packages/ssr/src/SsrPageHandler.php`
**Symptoms:** `GET /admin/bootstrap` returns 500. PHP logs: "Cannot resolve constructor parameter $catalogBuilder (CatalogBuilder)", plus `$authConfig`, `$transportConfig`, `$tenant`.
**Root cause:** `SsrPageHandler::resolveControllerInstance()` uses a hardcoded service map of 4 types (EntityTypeManager, Twig, HttpRequest, AccountInterface). It does not check the service container for registered bindings. The `AdminBridgeServiceProvider` registers `CatalogBuilder` in the container, but the SSR controller resolver never queries the container.
**Additionally:** `AdminBridgeServiceProvider::register()` only registers `CatalogBuilder` — it's missing registrations for `AdminAuthConfig`, `AdminTransportConfig`, and `AdminTenant`.
**Fix needed:**
1. Add missing service registrations to `AdminBridgeServiceProvider` (done during this session but not effective due to #2 above)
2. Add service container fallback to `SsrPageHandler::resolveControllerInstance()` so it can resolve arbitrary registered services

### 3. Admin plugin fetches wrong bootstrap URL

**Severity:** P0 — blocks admin SPA initialization even if #2 is fixed
**Location:** `packages/admin/app/plugins/admin.ts:17`
**Symptoms:** Plugin fetches `${baseUrl}/bootstrap` where `baseUrl` is empty string, resulting in `GET /bootstrap` (404). The actual endpoint is at `/admin/bootstrap`.
**Root cause:** In production, bootstrap is inlined via `window.__WAASEYAA_ADMIN__` by the SSR handler, so the fetch path is never used. In dev mode (SPA), there's no SSR to inline it, and the fallback fetch URL is wrong.
**Fix needed:** Either set `NUXT_PUBLIC_BASE_URL=/admin` in dev, or change the fetch path to `/admin/bootstrap`.

### 4. error.vue crashes when i18n plugin hasn't loaded

**Severity:** P1 — prevents error display, causes cascading blank page
**Location:** `packages/admin/app/error.vue:8`
**Symptoms:** When the admin plugin throws a fatal error (issue #2/#3), Nuxt tries to render `error.vue`. But `error.vue` calls `useI18n()` at line 8, which is undefined because the i18n plugin either hasn't loaded yet or was skipped during the crash. This causes two additional errors: `useI18n is not defined` and `$t is not a function`.
**Result:** Blank white page with only the Waaseyaa logo at the bottom — no error message shown to the user.
**Fix needed:** `error.vue` must not depend on i18n. Use hardcoded English strings or add a try/catch around `useI18n()`.

### 5. Dev environment requires undocumented env vars

**Severity:** P1 — blocks authentication for admin endpoints
**Location:** `packages/foundation/src/Kernel/HttpKernel.php:254-271`
**Symptoms:** All admin-bridge routes return 401 Unauthorized.
**Root cause:** `DevAdminAccount` requires both `APP_ENV=local` AND `WAASEYAA_DEV_FALLBACK_ACCOUNT=true`. The `.env.example` documents `WAASEYAA_DEV_FALLBACK_ACCOUNT` but doesn't make it clear both are needed. A developer running `composer dev` without these env vars gets silent 401s with no guidance.
**Fix needed:** Document the required env vars for local admin development. Consider auto-enabling dev fallback when `APP_ENV=local` and SAPI is `cli-server`.

## Non-blocking Observations

### 6. Production data incompatible with framework-only dev

The production database from `minoo.live` contains Claudriel-specific entity types (`community`, `event`, `volunteer`, `elder_support_request`, `resource_person`) that are not registered in the Waaseyaa entity type system. Only `user` (1 row) overlaps with core. Core entity types (`node`, `media`, `taxonomy_term`) have zero rows in production. This means smoke testing with production data requires the Claudriel application layer, not just the Waaseyaa framework.

### 7. Package manifest requires explicit rebuild

After adding `waaseyaa/admin-bridge` to the monorepo, routes were not discovered until running `bin/waaseyaa optimize:manifest`. The PHP dev server doesn't auto-rebuild the manifest, which can confuse developers. The manifest compilation also warns about missing optimized autoloader on every run.

### 8. SSR mode vs SPA mode mismatch

The admin SPA is designed to be served by the SSR handler (`AdminSpaController`) which inlines `window.__WAASEYAA_ADMIN__` into the HTML. When running in dev mode via `nuxt dev`, this SSR injection doesn't happen, and the SPA falls back to fetching `/bootstrap` — a path that doesn't match the registered route (`/admin/bootstrap`). The dev workflow for the admin SPA is fundamentally broken.

## Flows Tested

| # | Flow | Result | Blocker |
|---|------|--------|---------|
| 1 | Global shell | FAIL | App crashes during initialization |
| 2 | Dashboard | FAIL | App crashes during initialization |
| 3 | Entity list | FAIL | App crashes during initialization |
| 4 | Entity create | FAIL | App crashes during initialization |
| 5 | Entity edit | FAIL | App crashes during initialization |
| 6 | Error states | FAIL | error.vue crashes on missing i18n |
| 7 | Navigation | FAIL | App crashes during initialization |
| 8 | i18n switching | FAIL | i18n not initialized |
| 9 | SSE indicator | FAIL | App crashes during initialization |

**0 of 9 flows passed.**

## API Endpoint Tests

| Endpoint | Status | Notes |
|----------|--------|-------|
| `GET /api/entity-types` | 200 OK | 14 entity types listed |
| `GET /api/user` | 200 OK | 1 user returned |
| `GET /api/node` | 200 OK | Empty (no nodes in prod DB) |
| `GET /api/node_type` | 200 OK | Empty |
| `GET /admin/bootstrap` | 500 | Controller dependency resolution failure |

## Recommendations

1. **Fix the controller resolver** — `SsrPageHandler::resolveControllerInstance()` needs a service container fallback for arbitrary registered services. This is the architectural root cause.
2. **Fix admin dev workflow** — The admin SPA dev mode needs a working bootstrap endpoint. Either fix the URL mismatch or create a dev-mode bootstrap that doesn't go through SSR.
3. **Make error.vue resilient** — Don't depend on plugins that may not have loaded.
4. **Investigate SSR hang** — The `viteEnvironmentApi` experimental flag or the SSR proxy behavior needs debugging.
5. **Document dev setup** — Create a "getting started" section for admin UI development with required env vars.

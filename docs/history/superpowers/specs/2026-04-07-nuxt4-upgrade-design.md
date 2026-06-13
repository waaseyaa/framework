# Nuxt 4 Upgrade — Admin SPA

**Date:** 2026-04-07
**Branch:** feat/nuxt-surface-proxy
**Scope:** packages/admin

## Goal

Upgrade the admin SPA from Nuxt `^3.15.0` to Nuxt `^4` to stay ahead of Nuxt 3 moving to security-only maintenance. No new features. No SSR enablement — the admin is a control-plane SPA and SSR belongs to Waaseyaa's SSR package.

## Approach

Option A: focused migration on the current branch. Four sequential passes, one PR.

## Pass 1 — Fix PR issues (pre-upgrade cleanup)

**Delete:** `packages/admin/server/routes/admin/_surface/[...path].ts`

The server route was added to fix CORS during SSR, but `ssr: false` means there is no SSR. It duplicates the existing `routeRules` proxy and in Nitro's resolution order the server route wins, making the `routeRules` entry dead code. With SPA mode confirmed as permanent (SSR belongs to `waaseyaa/ssr`, not the admin), the server route is wrong and goes.

**Fix `nuxt.config.ts`:** Expose `backendUrl` via `runtimeConfig` so it is accessible at runtime:

```ts
runtimeConfig: {
  backendUrl: process.env.NUXT_BACKEND_URL ?? 'http://127.0.0.1:8080',
  public: {
    // existing keys unchanged
  },
},
```

`routeRules` stays unchanged — it is the canonical proxy mechanism for dev/preview:

```ts
routeRules: {
  '/api/**': { proxy: `${backendUrl}/api/**` },
  '/admin/_surface/**': { proxy: `${backendUrl}/admin/_surface/**` },
},
```

**Replace all `process.env.NUXT_BACKEND_URL` reads** outside `nuxt.config.ts` with `useRuntimeConfig().backendUrl`. The config-time `const backendUrl` in `nuxt.config.ts` stays — it is correct there.

## Pass 2 — Version bump

```
npx nuxi upgrade --force
```

Bumps `nuxt` and all first-party packages (`@nuxt/kit`, `@nuxt/schema`, etc.) in lock-step. Commit the lockfile before touching any source.

`@nuxt/test-utils` is already at `^4.0.0` — no change needed.

## Pass 3 — Breaking change fixes

Three known Nuxt 4 changes affect this codebase:

**1. `useFetch` / `useAsyncData` — shallow data by default**

`data` is now `ShallowRef<T>` instead of `Ref<T>`. Deep reactive mutations (`data.value.items.push(x)`) silently stop triggering reactivity.

Fix per affected call site (do not add `deep: true` blanket-wide):
- Add `{ deep: true }` option where the test suite proves deep mutation is needed, or
- Restructure to replace the whole value: `data.value = { ...data.value, items: [...] }`

**2. `useAsyncData` dedupe default: `cancel` → `defer`**

Audit all `useAsyncData` and `useFetch` call sites. Add `{ dedupe: 'cancel' }` explicitly where the old cancellation behaviour is required (typically in search/filter composables with rapid re-fetches).

**3. Plugin composition order**

Async plugins that touch reactive state must run after `vue-router` is ready. Verify `app/plugins/admin.ts` and any other async plugins are not calling composables — use `window.location.pathname` not `useRoute()` per CLAUDE.md.

## Pass 4 — Architectural audit

With tests green after pass 3, sweep the admin package against known CLAUDE.md gotchas:

| Check | Where to look |
|---|---|
| Async plugins calling composables (use `window.location.pathname`) | `app/plugins/` |
| `$fetch` calls missing `credentials: 'include'` | `app/composables/`, `app/plugins/` |
| `process.env` reads outside `nuxt.config.ts` | grep codebase |
| `routeRules` proxy still correct after version bump | `nuxt.config.ts` |
| `useRuntimeConfig()` used consistently for runtime values | all TS/Vue files |

## Data Flow

**Proxy (dev/preview only):**
```
Browser → Nitro (nuxt dev/preview)
  → routeRules: /api/** or /admin/_surface/**
  → PHP backend (NUXT_BACKEND_URL ?? http://127.0.0.1:8080)
```

In production static builds, the web server handles proxying directly. `routeRules` is a dev/preview concern only.

**Runtime config:**
```
NUXT_BACKEND_URL → runtimeConfig.backendUrl (private, server-only)
NUXT_PUBLIC_* → runtimeConfig.public.* (exposed to browser)
```

`backendUrl` is private — never exposed to the browser bundle.

## Files Changed

| File | Change |
|---|---|
| `server/routes/admin/_surface/[...path].ts` | Deleted |
| `nuxt.config.ts` | Add `runtimeConfig.backendUrl`; no other structural changes |
| `package.json` | `nuxt ^3.15.0` → `^4.x` (exact from nuxi upgrade output) |
| `app/plugins/admin.ts` | Verify (likely no change needed) |
| `app/composables/*.ts` | Targeted fixes for shallow data / dedupe per call site |
| `app/pages/**/*.vue` | Same — targeted only |

Everything else (tsconfig.json, vitest.config.ts, playwright.config.ts, layouts, components) is not expected to change.

## Validation Gate

All of the following must be green before the PR is marked ready:

```
npm run build        # TypeScript compile — zero errors
npm test             # Vitest unit + component — all green
npm run test:e2e     # Playwright — all green (requires nuxt dev running)
```

No new tests are written. The migration is validated by the existing suite.

## Out of Scope

- SSR enablement (belongs to `waaseyaa/ssr`)
- Adopting the new `shared/` directory
- Migrating to `useTemplateRef`
- Any PHP-side changes
- New test coverage

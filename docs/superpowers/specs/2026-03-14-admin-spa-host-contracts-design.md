# Waaseyaa Admin SPA — Host Contracts & Packaging Design

**Date:** 2026-03-14
**Status:** Approved
**Scope:** Turn the admin SPA from a prototype into a versioned, consumable product with explicit host contracts

## Problem Statement

The Waaseyaa admin SPA (`packages/admin/`) is currently a tightly-coupled prototype:

- Hardcodes `/api/user/me`, `/api/auth/login`, `/api/auth/logout`
- Hardcodes `/api/entity-types` for catalog discovery
- Hardcodes `/api/{type}` JSON:API CRUD
- Mounts only at root `/`
- Published as `"private": true`

Downstream apps like Claudriel had to:
- Invent `/admin/session` and `/admin/logout`
- Implement a tenant/bootstrap payload
- Implement an entity catalog
- Translate generic entity CRUD into bespoke APIs
- Patch Nuxt baseURL to `/admin/`
- Vendor the entire admin source and commit the built bundle

## Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Packaging | npm package + pre-built tarball (both) | npm for extensibility, tarball as default for PHP apps |
| Host configuration | Inline `window.__WAASEYAA_ADMIN__` + bootstrap endpoint fallback | Supports static hosting, CDN, and server-side templating |
| Login handoff | Pluggable strategy (`redirect` default, `embedded` opt-in) | Redirect is secure default; embedded for Waaseyaa's own host |
| Entity transport | Abstract `TransportAdapter` interface, JSON:API default adapter | Eliminates need for vendoring/forking when backend isn't JSON:API |
| Tenant context | Display-only with optional header scoping (`server` default) | Server-side scoping is secure default; header opt-in for advanced hosts |
| Entity catalog | In bootstrap payload (no separate endpoint) | Keeps contract surface small; entity types rarely change mid-session |
| Capabilities | Boolean flags per entity type | Finite, well-defined set; self-documenting in TypeScript |
| Widget extensibility | Fixed set, extensible only via npm/Nuxt layer path | Keeps tarball simple; no runtime widget registry |
| Internal architecture | Adapter interfaces + Nuxt plugin (`useNuxtApp().$admin`) | Clean separation: contracts are reusable, Nuxt wiring is framework-specific |

---

## Section 1: Contract Interfaces

All contracts live in `packages/admin/app/contracts/` as pure TypeScript — no Vue, Nuxt, or runtime-specific imports. All are exportable from the package root.

### Contract Version

```typescript
export const ADMIN_CONTRACT_VERSION = '1.0'
```

### Auth Contracts

```typescript
export interface AuthAdapter {
  /** Resolve current session. Returns null if unauthenticated. */
  getSession(): Promise<AdminSession | null>

  /** Refresh an existing session (e.g., token rotation). Optional. */
  refreshSession?(): Promise<AdminSession | null>

  /** Log out. Adapter decides whether to call an endpoint or redirect. */
  logout(): Promise<void>

  /** Where to send unauthenticated users. */
  getLoginUrl(returnTo: string): string
}

export interface AdminSession {
  account: AdminAccount
  tenant: AdminTenant
  features?: Record<string, boolean>
}

export interface AdminAccount {
  id: string            // Always string in the contract. PHP bridge serializes numeric UIDs as strings.
  name: string
  email?: string        // Optional. Present when host provides it; omitted otherwise.
  roles: string[]
}

export interface AdminTenant {
  id: string
  name: string
  scopingStrategy: 'server' | 'header'  // default: 'server'
}
```

### Catalog Contracts

```typescript
export interface CatalogEntry {
  id: string
  label: string
  keys?: Record<string, string>   // Entity key names (e.g., { id: 'nid', uuid: 'uuid' }). Optional for hosts that don't expose key metadata.
  group?: string
  disabled?: boolean
  capabilities: CatalogCapabilities
}

export interface CatalogCapabilities {
  list: boolean
  get: boolean
  create: boolean
  update: boolean
  delete: boolean
  schema: boolean
}
```

### Transport Contracts

```typescript
export interface TransportAdapter {
  list(type: string, query?: ListQuery): Promise<ListResult>
  get(type: string, id: string): Promise<EntityResource>
  create(type: string, attributes: Record<string, any>): Promise<EntityResource>
  update(type: string, id: string, attributes: Record<string, any>): Promise<EntityResource>
  remove(type: string, id: string): Promise<void>
  schema(type: string): Promise<EntitySchema>
  search(type: string, field: string, query: string, limit?: number): Promise<EntityResource[]>
}

export interface ListQuery {
  page?: { offset: number; limit: number }
  sort?: string
  filter?: Record<string, { operator: string; value: string }>
}

export interface ListResult {
  data: EntityResource[]
  meta: { total: number; offset: number; limit: number }
}

export interface EntityResource {
  type: string
  id: string
  attributes: Record<string, any>
}
```

### Schema Contracts

```typescript
export interface SchemaProperty {
  type: string
  description?: string
  format?: string
  readOnly?: boolean
  default?: any
  enum?: string[]
  minimum?: number
  maximum?: number
  maxLength?: number
  'x-widget'?: string
  'x-label'?: string
  'x-description'?: string
  'x-weight'?: number
  'x-required'?: boolean
  'x-enum-labels'?: Record<string, string>
  'x-target-type'?: string
  'x-access-restricted'?: boolean
  'x-source-field'?: string
  'x-list-display'?: boolean
}

export interface EntitySchema {
  $schema: string
  title: string
  description: string
  type: string
  'x-entity-type': string
  'x-translatable': boolean
  'x-revisionable': boolean
  properties: Record<string, SchemaProperty>
  required?: string[]
}
```

### Bootstrap Contract

```typescript
export interface AdminBootstrap {
  version: typeof ADMIN_CONTRACT_VERSION
  auth: AdminAuthConfig
  account: AdminAccount
  tenant: AdminTenant
  transport: AdminTransportConfig
  entities: CatalogEntry[]
  features?: Record<string, boolean>
}

export interface AdminAuthConfig {
  strategy: 'redirect' | 'embedded'  // default: 'redirect'
  loginUrl?: string                   // required when strategy is 'redirect'
  loginEndpoint?: string              // required when strategy is 'embedded'
  logoutEndpoint?: string
}

export interface AdminTransportConfig {
  strategy: 'jsonapi' | 'custom'      // default: 'jsonapi'
  apiPath?: string                    // API path prefix for the default JSON:API adapter (e.g., '/api')
}
```

### Transport Errors

```typescript
export class TransportError extends Error {
  constructor(
    public readonly status: number,       // HTTP status code (404, 422, 500, etc.)
    public readonly title: string,        // Human-readable error title
    public readonly detail?: string,      // Optional detail message
    public readonly source?: Record<string, string>,  // Optional pointer to offending field
  ) {
    super(title)
    this.name = 'TransportError'
  }
}
```

Custom `TransportAdapter` implementations must throw `TransportError` for all error conditions. The SPA catches these and renders appropriate error UI based on `status`.

### Auth Lifecycle

`AuthAdapter.refreshSession?()` is called by the SPA in exactly one situation: when a `TransportAdapter` method throws a `TransportError` with `status: 401`. The SPA calls `refreshSession()` once. If it returns a valid `AdminSession`, the SPA retries the failed transport call. If it returns `null` or is not implemented, the SPA redirects to the login URL. There is no timer-based refresh.

### Session vs Bootstrap: Source of Truth

`AdminBootstrap.account` and `AdminBootstrap.tenant` are the **initial** values, set at boot time. `AdminSession` (returned by `AuthAdapter.getSession()` and `refreshSession()`) is the **runtime** source of truth. If a session refresh returns different account/tenant values, the SPA updates its runtime state from the session, not the original bootstrap. For the default `BootstrapAuthAdapter`, these are always the same object since there is no refresh.

### Global Type Augmentation

For inline bootstrap via `window.__WAASEYAA_ADMIN__`:

```typescript
declare global {
  interface Window {
    __WAASEYAA_ADMIN__?: AdminBootstrap
  }
}
```

This augmentation is included in `contracts/bootstrap.ts`.

### Runtime Interface (Nuxt plugin provides this)

```typescript
export interface AdminRuntime {
  bootstrap: AdminBootstrap
  auth: AuthAdapter
  transport: TransportAdapter
  catalog: CatalogEntry[]
  tenant: AdminTenant
}
```

---

## Section 2: SPA Internals Refactor

### File organization

New and modified files in `packages/admin/`:

```
app/
  contracts/                          # Pure TypeScript — no Vue imports
    index.ts                          # Re-exports all contract types
    auth.ts                           # AuthAdapter, AdminSession, AdminAccount, AdminTenant
    catalog.ts                        # CatalogEntry, CatalogCapabilities
    transport.ts                      # TransportAdapter, ListQuery, ListResult, EntityResource
    schema.ts                         # EntitySchema, SchemaProperty
    bootstrap.ts                      # AdminBootstrap, AdminAuthConfig, AdminTransportConfig
    version.ts                        # ADMIN_CONTRACT_VERSION
    runtime.ts                        # AdminRuntime

  adapters/                           # Default implementations
    index.ts                          # Re-exports all adapters
    JsonApiTransportAdapter.ts        # TransportAdapter speaking JSON:API via fetch/ofetch
    BootstrapAuthAdapter.ts           # AuthAdapter using bootstrap payload (no network calls)

  plugins/
    admin.ts                          # Nuxt plugin — resolves bootstrap, instantiates adapters

  composables/                        # Existing, refactored
    useAuth.ts                        # Thin wrapper — injects AuthAdapter from $admin
    useEntity.ts                      # Thin wrapper — injects TransportAdapter from $admin
    useSchema.ts                      # Thin wrapper — injects TransportAdapter.schema()
    useAdmin.ts                       # NEW — access to AdminRuntime: catalog, tenant, helpers
    useNavGroups.ts                   # Unchanged (pure UI grouping logic)
    useLanguage.ts                    # Unchanged
    useRealtime.ts                    # Unchanged
```

### Bootstrap resolution flow (Nuxt plugin `admin.ts`)

```
1. Compute resolvedBaseUrl from NUXT_PUBLIC_BASE_URL (centralized, computed once)
2. Check window.__WAASEYAA_ADMIN__ → AdminBootstrap?
3. If not found, fetch GET <resolvedBaseUrl>/bootstrap
4. If 401 → redirect to auth.loginUrl (or show embedded form)
5. Validate bootstrap.version against ADMIN_CONTRACT_VERSION
   - If mismatch → show error screen, refuse to boot
6. Instantiate adapters:
   - auth ← BootstrapAuthAdapter(bootstrap)
     - getSession() returns { account, tenant, features } from bootstrap (no fetch)
     - logout() calls logoutEndpoint via fetch
     - getLoginUrl(returnTo) returns auth.loginUrl + ?returnTo=...
   - transport ← JsonApiTransportAdapter(resolvedBaseUrl + transport.apiPath, tenant)
     - When tenant.scopingStrategy === 'header', injects X-Tenant-Id on every request
     - Uses fetch/ofetch directly (NOT Nuxt $fetch) for framework independence
7. Build AdminRuntime and provide via nuxtApp.provide('admin', runtime)
```

### Composable changes

**`useAuth.ts`** — Injects `$admin.auth`. `login()` checks `bootstrap.auth.strategy`: if `redirect`, navigates to `auth.getLoginUrl()`; if `embedded`, posts to `auth.loginEndpoint`. `logout()` delegates to `auth.logout()`. `checkAuth()` delegates to `auth.getSession()`.

**`useEntity.ts`** — Injects `$admin.transport`. All methods delegate directly: `list()` → `transport.list()`, `get()` → `transport.get()`, etc. No JSON:API envelope logic — that's in the adapter.

**`useSchema.ts`** — Schema fetching delegates to `transport.schema()`. Caching (`Map<string, EntitySchema>`) and `sortedProperties()` remain in the composable (UI concerns).

**`useAdmin.ts`** (new) — Provides:
- `bootstrap` — the full resolved `AdminBootstrap`
- `catalog` — `CatalogEntry[]`
- `tenant` — `AdminTenant`
- `hasCapability(entityType, cap)` — checks a specific capability flag
- `getEntity(type)` — returns `CatalogEntry | undefined` for a given entity type ID

### Component changes

- **`NavBuilder.vue`** — uses `useAdmin().catalog` instead of fetching `/api/entity-types`
- **`index.vue` (dashboard)** — uses `useAdmin().catalog` instead of fetching `/api/entity-types`
- **`AdminShell.vue`** — reads `useAdmin().tenant.name` for UI chrome
- **`SchemaList.vue`** — uses `useAdmin().hasCapability()` to show/hide create/delete buttons
- **All widget components** — unchanged
- **`SchemaForm.vue`, `SchemaField.vue`** — unchanged (consume `EntitySchema`, same shape)

### BaseURL / subpath mounting

`nuxt.config.ts` reads `NUXT_PUBLIC_BASE_URL` (default `/`). This sets:
- Nuxt's `app.baseURL`
- The bootstrap endpoint path: `<baseURL>/bootstrap`
- The JSON:API adapter base: `<baseURL>` + `transport.apiPath`

Claudriel sets `NUXT_PUBLIC_BASE_URL=/admin/` and everything resolves.

### Composables requiring minor updates

**`useNavGroups.ts`** — Currently operates on `EntityTypeInfo` (with `keys`). Updated to accept `CatalogEntry[]` instead. The `keys` field is now optional on `CatalogEntry`, so `groupEntityTypes()` continues to work. The `ResolvedNavGroup` interface updates its item type from `EntityTypeInfo` to `CatalogEntry`.

### Login page (`login.vue`)

The existing `login.vue` page becomes the **embedded login form**. It is only reachable when `auth.strategy === 'embedded'`. When the strategy is `redirect`, the Nuxt plugin redirects to `auth.loginUrl` before the page can render. The page's form action changes from hardcoded `/api/auth/login` to `bootstrap.auth.loginEndpoint`.

### Telescope & Codified Context

`useCodifiedContext.ts` and the telescope pages (`telescope/codified-context/`) are **out of scope** for the transport adapter abstraction. These are Waaseyaa-internal developer tools, not host-facing admin features. They continue to use hardcoded `/api/telescope/*` endpoints. Tarball consumers that don't expose telescope endpoints simply won't navigate to those pages. A future version may add a `telescope` capability flag to the catalog, but this is not part of the v1.0 contract.

### What doesn't change

- All widget components
- `SchemaForm`, `SchemaField` (consume `EntitySchema`, same shape)
- `useLanguage`, `useRealtime`
- Page routes (file-based routing)
- CSS/styling

---

## Section 3: Packaging & PHP Bridge

### npm package: `@waaseyaa/admin`

Remove `"private": true`. Three export paths:

```json
{
  "name": "@waaseyaa/admin",
  "version": "1.0.0",
  "type": "module",
  "exports": {
    ".": "./dist/contracts/index.js",
    "./adapters": "./dist/adapters/index.js",
    "./nuxt": "./layer/"
  },
  "types": "./dist/contracts/index.d.ts",
  "files": [
    "dist/contracts/",
    "dist/adapters/",
    "layer/"
  ]
}
```

- **`import { TransportAdapter } from '@waaseyaa/admin'`** — contract types only, pure TS
- **`import { JsonApiTransportAdapter } from '@waaseyaa/admin/adapters'`** — default adapter implementations
- **`extends: ['@waaseyaa/admin/nuxt']`** — full SPA as a Nuxt layer

The Nuxt layer lives in `packages/admin/layer/` with its own `nuxt.config.ts`, `app/`, `plugins/`, etc.

### Pre-built tarball

CI builds a static SPA and attaches to GitHub releases:

```
waaseyaa-admin-v1.0.0.tar.gz
  └── admin/
      ├── index.html
      ├── _nuxt/
      │   ├── entry.*.js
      │   ├── *.css
      │   └── ...
      ├── 200.html                    # SPA fallback for static hosting (Netlify, Surge). PHP bridge ignores this — uses AdminSpaController instead.
      └── waaseyaa-admin.json         # Manifest: { "version": "1.0.0", "contract": "1.0" }
```

Build: `NUXT_PUBLIC_BASE_URL=/admin/ npx nuxt generate`

### PHP Bridge: `waaseyaa/admin-bridge`

A Composer package in `packages/admin-bridge/`:

```
packages/admin-bridge/
  composer.json
  src/
    AdminBootstrapPayload.php         # Value object → AdminBootstrap JSON
    AdminBootstrapController.php      # GET /admin/bootstrap handler
    AdminSpaController.php            # Serves index.html with correct headers
    CatalogBuilder.php                # Builds CatalogEntry[] from EntityTypeManager
    AdminBridgeServiceProvider.php    # Wires routes and services
  tests/
    Unit/
      AdminBootstrapPayloadTest.php   # Contract conformance
      CatalogBuilderTest.php
```

**`AdminBootstrapPayload`** — PHP value object with `toArray()` producing the `AdminBootstrap` JSON shape. Validates contract version on construction:

```php
if ($this->version !== '1.0') {
    throw new \RuntimeException("Unsupported admin contract version: {$this->version}");
}
```

**`AdminBootstrapController`** — Returns 401 as a real HTTP status (not a JSON body) when unauthenticated. Returns `AdminBootstrapPayload::toArray()` as JSON when authenticated.

**`AdminSpaController`** — Serves `index.html` with:
- `Content-Type: text/html; charset=utf-8`
- `Cache-Control: no-cache` (index.html must not be cached; static assets in `_nuxt/` are cache-busted by filename hash)

**`CatalogBuilder`** — Builds `CatalogEntry[]` from `EntityTypeManager`. Enforces capability defaults when host omits them:

```php
private const DEFAULT_CAPABILITIES = [
    'list' => true, 'get' => true, 'create' => false,
    'update' => false, 'delete' => false, 'schema' => true,
];
```

### Waaseyaa's own host integration

`HttpKernel` adds:
- `GET /admin/bootstrap` → `AdminBootstrapController`
- `GET /admin/{path}` → `AdminSpaController` (catch-all for SPA routing)

Default transport: `jsonapi` pointing at `/api`. Default auth strategy: `embedded` (Waaseyaa's own login form).

---

## Section 4: CI & Testing

### CI workflow: `.github/workflows/admin.yml`

```yaml
name: Admin SPA
on:
  push:
    paths: ['packages/admin/**', 'packages/admin-bridge/**']
  pull_request:
    paths: ['packages/admin/**', 'packages/admin-bridge/**']
```

### Job 1: Contract Conformance

- TypeScript: `npx tsc --noEmit` on contract files
- TypeScript: Vitest tests validate fixture payloads satisfy interfaces
- PHP: PHPUnit tests validate `AdminBootstrapPayload::toArray()` output
- Cross-language: both sides validate against shared JSON schema (`contracts/bootstrap.schema.json`)

### Job 2: Adapter Unit Tests

**`JsonApiTransportAdapter`** (Vitest, mocked `fetch`):
- `list()` → sends correct query params, normalizes JSON:API response to `ListResult`
- `get()`, `create()`, `update()`, `remove()` → correct HTTP methods and normalization
- `schema()` → extracts `meta.schema`
- `search()` → sends `STARTS_WITH` filter
- Tenant header: present only when `scopingStrategy === 'header'`
- Error responses: 404, 422, 500 throw typed errors

**`BootstrapAuthAdapter`** (Vitest):
- `getSession()` → returns session from bootstrap payload (no network)
- `logout()` → calls `logoutEndpoint` via fetch
- `getLoginUrl()` → appends `returnTo` query param
- `refreshSession()` → returns null (no-op)

### Job 3: Integration Smoke Tests

Uses a mock host server (`tests/integration/mock-host.ts`) and Playwright:

- **Bootstrap resolution:** inline config, endpoint, mismatch, 401, 500
- **Auth flows:** redirect strategy redirects; embedded strategy shows form
- **Dashboard:** renders catalog entries from bootstrap payload
- **Entity CRUD:** list, create, update, delete via transport adapter
- **Capabilities:** disabled capabilities hide UI elements
- **Tenant scoping:** `server` sends no header; `header` sends `X-Tenant-Id`
- **Contract mismatch:** `version: "2.0"` → SPA refuses to boot with clear error
- **Custom adapter:** mock host with non-JSON:API transport, injected via Nuxt layer

### Job 4: Contract Freeze (release only)

Runs only on `refs/tags/admin-v*`:

```bash
./scripts/assert-contract-frozen.sh
```

Compares `app/contracts/`, `contracts/bootstrap.schema.json`, and `packages/admin-bridge/src/Admin*.php` against the last published tag. Fails if any contract file changed — forces intentional version bumps.

### Job 5: Tarball Integrity (release only)

After `nuxt generate`, validates:
- `index.html` exists
- `_nuxt/` directory exists
- `waaseyaa-admin.json` exists with correct version and contract fields
- No `.ts` files in output
- No `.map` files in output (optional, configurable)

### Job 6: PHP Bridge Integration (release only)

Spins up the PHP built-in server with `AdminBootstrapController` + `AdminSpaController`, mounts the generated tarball, and validates:
- `GET /admin/bootstrap` → 200 with valid payload
- `GET /admin/` → 200 with `text/html`
- SPA boots in Playwright against the PHP host

### Job 7: Nuxt Layer Consumption (release only)

A minimal downstream Nuxt app in `tests/nuxt-consumer/`:
- Extends `@waaseyaa/admin/nuxt`
- Overrides one page and one widget
- Runs `npm run build` successfully

### Job 8: Release & Publish

Runs after all other jobs pass on `refs/tags/admin-v*`:
- Generates tarball with manifest
- Attaches to GitHub release
- Publishes `@waaseyaa/admin` to npm
- (PHP bridge published to Packagist separately via existing split workflow)

---

## Migration Path for Claudriel

1. **Remove vendored admin SPA source** — delete the entire copied `packages/admin/` from Claudriel
2. **Download tarball** — extract `waaseyaa-admin-v1.0.0.tar.gz` to `public/admin/`
3. **Implement `AdminBootstrapController`** — use Claudriel's existing session/tenant logic to build `AdminBootstrapPayload`
4. **Implement custom `TransportAdapter`** (if not using JSON:API) — or set `transport.strategy: 'jsonapi'` if Claudriel now exposes JSON:API endpoints
5. **Set auth strategy to `redirect`** — point `loginUrl` at Claudriel's existing login page
6. **Remove all admin-specific endpoint patches** — `/admin/session`, `/admin/logout`, entity catalog translation
7. **Serve SPA** — mount `AdminSpaController` at `/admin/{path}`

---

## Versioning Strategy

- **Additive changes** (new optional fields) → same contract version, patch/minor npm bump
- **Breaking changes** (removed/renamed fields, changed semantics) → contract version bump + major npm bump
- **SPA validates** `bootstrap.version` on boot and rejects unknown major versions with a clear error screen
- **Contract freeze CI job** prevents accidental breaking changes in releases

# Admin SPA Host Contracts Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor the Waaseyaa admin SPA from hardcoded API endpoints to injectable host contracts, enabling downstream apps to consume it as a versioned npm package or pre-built tarball without vendoring.

**Architecture:** Pure TypeScript contract interfaces define four adapters (auth, transport, catalog, tenant). A Nuxt plugin resolves configuration from `window.__WAASEYAA_ADMIN__` or a `GET <baseURL>/bootstrap` endpoint, instantiates default adapters, and provides them via `useNuxtApp().$admin`. Composables become thin wrappers over injected adapters. A PHP bridge package provides value objects and controllers for hosts.

**Tech Stack:** Nuxt 3.15, Vue 3.5, TypeScript 5.6, Vitest 4, Playwright, PHPUnit 10.5, PHP 8.3

**Spec:** `docs/superpowers/specs/2026-03-14-admin-spa-host-contracts-design.md`

---

## Chunk 1: Contract Interfaces & Adapters

### Task 1: Create contract type files

**Files:**
- Create: `packages/admin/app/contracts/version.ts`
- Create: `packages/admin/app/contracts/auth.ts`
- Create: `packages/admin/app/contracts/catalog.ts`
- Create: `packages/admin/app/contracts/transport.ts`
- Create: `packages/admin/app/contracts/schema.ts`
- Create: `packages/admin/app/contracts/bootstrap.ts`
- Create: `packages/admin/app/contracts/runtime.ts`
- Create: `packages/admin/app/contracts/index.ts`

- [ ] **Step 1: Create `contracts/version.ts`**

```typescript
// packages/admin/app/contracts/version.ts
export const ADMIN_CONTRACT_VERSION = '1.0'
```

- [ ] **Step 2: Create `contracts/auth.ts`**

```typescript
// packages/admin/app/contracts/auth.ts
export interface AuthAdapter {
  getSession(): Promise<AdminSession | null>
  refreshSession?(): Promise<AdminSession | null>
  logout(): Promise<void>
  getLoginUrl(returnTo: string): string
}

export interface AdminSession {
  account: AdminAccount
  tenant: AdminTenant
  features?: Record<string, boolean>
}

export interface AdminAccount {
  id: string
  name: string
  email?: string
  roles: string[]
}

export interface AdminTenant {
  id: string
  name: string
  scopingStrategy: 'server' | 'header'
}
```

- [ ] **Step 3: Create `contracts/catalog.ts`**

```typescript
// packages/admin/app/contracts/catalog.ts
export interface CatalogEntry {
  id: string
  label: string
  keys?: Record<string, string>
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

- [ ] **Step 4: Create `contracts/transport.ts`**

```typescript
// packages/admin/app/contracts/transport.ts
import type { EntitySchema } from './schema'

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

export class TransportError extends Error {
  constructor(
    public readonly status: number,
    public readonly title: string,
    public readonly detail?: string,
    public readonly source?: Record<string, string>,
  ) {
    super(title)
    this.name = 'TransportError'
  }
}
```

- [ ] **Step 5: Create `contracts/schema.ts`**

Move the `SchemaProperty` and `EntitySchema` interfaces from `composables/useSchema.ts` to the contracts layer. These are the same interfaces — just relocated.

```typescript
// packages/admin/app/contracts/schema.ts
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

- [ ] **Step 6: Create `contracts/bootstrap.ts`**

```typescript
// packages/admin/app/contracts/bootstrap.ts
import type { AdminAccount, AdminTenant } from './auth'
import type { CatalogEntry } from './catalog'
import { ADMIN_CONTRACT_VERSION } from './version'

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
  strategy: 'redirect' | 'embedded'
  loginUrl?: string
  loginEndpoint?: string
  logoutEndpoint?: string
}

export interface AdminTransportConfig {
  strategy: 'jsonapi' | 'custom'
  apiPath?: string
}

declare global {
  interface Window {
    __WAASEYAA_ADMIN__?: AdminBootstrap
  }
}
```

- [ ] **Step 7: Create `contracts/runtime.ts`**

```typescript
// packages/admin/app/contracts/runtime.ts
import type { AdminBootstrap } from './bootstrap'
import type { AuthAdapter } from './auth'
import type { TransportAdapter } from './transport'
import type { CatalogEntry } from './catalog'
import type { AdminTenant } from './auth'

export interface AdminRuntime {
  bootstrap: AdminBootstrap
  auth: AuthAdapter
  transport: TransportAdapter
  catalog: CatalogEntry[]
  tenant: AdminTenant
}
```

- [ ] **Step 8: Create `contracts/index.ts`**

```typescript
// packages/admin/app/contracts/index.ts
export { ADMIN_CONTRACT_VERSION } from './version'
export type { AuthAdapter, AdminSession, AdminAccount, AdminTenant } from './auth'
export type { CatalogEntry, CatalogCapabilities } from './catalog'
export type { TransportAdapter, ListQuery, ListResult, EntityResource } from './transport'
export { TransportError } from './transport'
export type { SchemaProperty, EntitySchema } from './schema'
export type { AdminBootstrap, AdminAuthConfig, AdminTransportConfig } from './bootstrap'
export type { AdminRuntime } from './runtime'
```

- [ ] **Step 9: Verify contracts compile**

Run: `cd packages/admin && npx nuxi typecheck`
Expected: No errors from `app/contracts/` files.

- [ ] **Step 10: Commit**

```bash
git add packages/admin/app/contracts/
git commit -m "feat(admin): add host contract interfaces

Pure TypeScript interfaces for AuthAdapter, TransportAdapter,
CatalogEntry, AdminBootstrap, and AdminRuntime. No Vue/Nuxt deps."
```

---

### Task 2: Create default adapters

**Files:**
- Create: `packages/admin/app/adapters/BootstrapAuthAdapter.ts`
- Create: `packages/admin/app/adapters/JsonApiTransportAdapter.ts`
- Create: `packages/admin/app/adapters/index.ts`
- Test: `packages/admin/tests/unit/adapters/BootstrapAuthAdapter.test.ts`
- Test: `packages/admin/tests/unit/adapters/JsonApiTransportAdapter.test.ts`

- [ ] **Step 1: Write failing test for BootstrapAuthAdapter**

```typescript
// packages/admin/tests/unit/adapters/BootstrapAuthAdapter.test.ts
import { describe, it, expect, vi } from 'vitest'
import { BootstrapAuthAdapter } from '~/adapters/BootstrapAuthAdapter'
import type { AdminBootstrap } from '~/contracts'

function makeBootstrap(overrides: Partial<AdminBootstrap> = {}): AdminBootstrap {
  return {
    version: '1.0',
    auth: { strategy: 'redirect', loginUrl: '/login' },
    account: { id: '1', name: 'Admin', roles: ['admin'] },
    tenant: { id: 'default', name: 'Test', scopingStrategy: 'server' },
    transport: { strategy: 'jsonapi', apiPath: '/api' },
    entities: [],
    ...overrides,
  }
}

describe('BootstrapAuthAdapter', () => {
  it('getSession returns session from bootstrap without network call', async () => {
    const bootstrap = makeBootstrap()
    const adapter = new BootstrapAuthAdapter(bootstrap)
    const session = await adapter.getSession()
    expect(session).toEqual({
      account: bootstrap.account,
      tenant: bootstrap.tenant,
      features: undefined,
    })
  })

  it('getSession includes features when present', async () => {
    const bootstrap = makeBootstrap({ features: { darkMode: true } })
    const adapter = new BootstrapAuthAdapter(bootstrap)
    const session = await adapter.getSession()
    expect(session!.features).toEqual({ darkMode: true })
  })

  it('logout calls logoutEndpoint via fetch', async () => {
    const mockFetch = vi.fn().mockResolvedValue(new Response())
    const bootstrap = makeBootstrap({
      auth: { strategy: 'redirect', loginUrl: '/login', logoutEndpoint: '/auth/logout' },
    })
    const adapter = new BootstrapAuthAdapter(bootstrap, mockFetch)
    await adapter.logout()
    expect(mockFetch).toHaveBeenCalledWith('/auth/logout', { method: 'POST' })
  })

  it('logout is a no-op when no logoutEndpoint', async () => {
    const mockFetch = vi.fn()
    const bootstrap = makeBootstrap()
    const adapter = new BootstrapAuthAdapter(bootstrap, mockFetch)
    await adapter.logout()
    expect(mockFetch).not.toHaveBeenCalled()
  })

  it('getLoginUrl returns loginUrl with returnTo param', () => {
    const bootstrap = makeBootstrap({
      auth: { strategy: 'redirect', loginUrl: '/login' },
    })
    const adapter = new BootstrapAuthAdapter(bootstrap)
    expect(adapter.getLoginUrl('/admin/dashboard')).toBe('/login?returnTo=%2Fadmin%2Fdashboard')
  })

  it('refreshSession returns null (no-op)', async () => {
    const adapter = new BootstrapAuthAdapter(makeBootstrap())
    const result = await adapter.refreshSession!()
    expect(result).toBeNull()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/admin && npx vitest run tests/unit/adapters/BootstrapAuthAdapter.test.ts`
Expected: FAIL — module `~/adapters/BootstrapAuthAdapter` not found.

- [ ] **Step 3: Implement BootstrapAuthAdapter**

```typescript
// packages/admin/app/adapters/BootstrapAuthAdapter.ts
import type { AuthAdapter, AdminSession } from '../contracts/auth'
import type { AdminBootstrap } from '../contracts/bootstrap'

export class BootstrapAuthAdapter implements AuthAdapter {
  constructor(
    private readonly bootstrap: AdminBootstrap,
    private readonly fetchFn: typeof fetch = fetch,
  ) {}

  async getSession(): Promise<AdminSession | null> {
    return {
      account: this.bootstrap.account,
      tenant: this.bootstrap.tenant,
      features: this.bootstrap.features,
    }
  }

  async refreshSession(): Promise<AdminSession | null> {
    return null
  }

  async logout(): Promise<void> {
    const endpoint = this.bootstrap.auth.logoutEndpoint
    if (endpoint) {
      await this.fetchFn(endpoint, { method: 'POST' })
    }
  }

  getLoginUrl(returnTo: string): string {
    const loginUrl = this.bootstrap.auth.loginUrl ?? '/login'
    const separator = loginUrl.includes('?') ? '&' : '?'
    return `${loginUrl}${separator}returnTo=${encodeURIComponent(returnTo)}`
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/admin && npx vitest run tests/unit/adapters/BootstrapAuthAdapter.test.ts`
Expected: All 6 tests PASS.

- [ ] **Step 5: Write failing test for JsonApiTransportAdapter**

```typescript
// packages/admin/tests/unit/adapters/JsonApiTransportAdapter.test.ts
import { describe, it, expect, vi } from 'vitest'
import { JsonApiTransportAdapter } from '~/adapters/JsonApiTransportAdapter'
import { TransportError } from '~/contracts'

function mockFetchResponse(data: any, status = 200) {
  return vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(data),
  } as unknown as Response)
}

function makeAdapter(fetchFn: typeof fetch, apiPath = '/api') {
  return new JsonApiTransportAdapter(apiPath, { id: 'default', name: 'Test', scopingStrategy: 'server' }, fetchFn)
}

describe('JsonApiTransportAdapter', () => {
  describe('list', () => {
    it('sends GET to /api/{type} and normalizes JSON:API response', async () => {
      const jsonApiResponse = {
        jsonapi: { version: '1.1' },
        data: [{ type: 'node', id: '1', attributes: { title: 'Hello' } }],
        meta: { total: 1, offset: 0, limit: 25 },
      }
      const fetchFn = mockFetchResponse(jsonApiResponse)
      const adapter = makeAdapter(fetchFn)
      const result = await adapter.list('node')
      expect(fetchFn).toHaveBeenCalledWith('/api/node', expect.objectContaining({ method: 'GET' }))
      expect(result.data).toEqual([{ type: 'node', id: '1', attributes: { title: 'Hello' } }])
      expect(result.meta.total).toBe(1)
    })

    it('sends pagination and sort query params', async () => {
      const fetchFn = mockFetchResponse({ data: [], meta: { total: 0, offset: 0, limit: 10 } })
      const adapter = makeAdapter(fetchFn)
      await adapter.list('node', { page: { offset: 20, limit: 10 }, sort: '-title' })
      const calledUrl = fetchFn.mock.calls[0][0] as string
      expect(calledUrl).toContain('page%5Boffset%5D=20')
      expect(calledUrl).toContain('page%5Blimit%5D=10')
      expect(calledUrl).toContain('sort=-title')
    })
  })

  describe('get', () => {
    it('sends GET to /api/{type}/{id} and returns EntityResource', async () => {
      const fetchFn = mockFetchResponse({
        data: { type: 'node', id: '5', attributes: { title: 'Post' } },
      })
      const adapter = makeAdapter(fetchFn)
      const result = await adapter.get('node', '5')
      expect(result).toEqual({ type: 'node', id: '5', attributes: { title: 'Post' } })
    })
  })

  describe('create', () => {
    it('sends POST with JSON:API body', async () => {
      const resource = { type: 'node', id: '6', attributes: { title: 'New' } }
      const fetchFn = mockFetchResponse({ data: resource }, 201)
      const adapter = makeAdapter(fetchFn)
      await adapter.create('node', { title: 'New' })
      const [, opts] = fetchFn.mock.calls[0]
      expect(opts.method).toBe('POST')
      const body = JSON.parse(opts.body)
      expect(body.data.type).toBe('node')
      expect(body.data.attributes.title).toBe('New')
    })
  })

  describe('update', () => {
    it('sends PATCH with JSON:API body including id', async () => {
      const fetchFn = mockFetchResponse({
        data: { type: 'node', id: '3', attributes: { title: 'Updated' } },
      })
      const adapter = makeAdapter(fetchFn)
      await adapter.update('node', '3', { title: 'Updated' })
      const [url, opts] = fetchFn.mock.calls[0]
      expect(url).toBe('/api/node/3')
      expect(opts.method).toBe('PATCH')
      const body = JSON.parse(opts.body)
      expect(body.data.id).toBe('3')
    })
  })

  describe('remove', () => {
    it('sends DELETE to /api/{type}/{id}', async () => {
      const fetchFn = mockFetchResponse(null, 204)
      const adapter = makeAdapter(fetchFn)
      await adapter.remove('node', '5')
      expect(fetchFn).toHaveBeenCalledWith('/api/node/5', expect.objectContaining({ method: 'DELETE' }))
    })
  })

  describe('schema', () => {
    it('extracts schema from meta.schema', async () => {
      const schema = {
        $schema: 'https://json-schema.org/draft-07/schema#',
        title: 'Content',
        description: 'Schema for Content entities.',
        type: 'object',
        'x-entity-type': 'node',
        'x-translatable': false,
        'x-revisionable': false,
        properties: { title: { type: 'string' } },
      }
      const fetchFn = mockFetchResponse({ meta: { schema } })
      const adapter = makeAdapter(fetchFn)
      const result = await adapter.schema('node')
      expect(result).toEqual(schema)
    })
  })

  describe('search', () => {
    it('sends STARTS_WITH filter query', async () => {
      const fetchFn = mockFetchResponse({ data: [] })
      const adapter = makeAdapter(fetchFn)
      await adapter.search('user', 'name', 'jo', 10)
      const calledUrl = fetchFn.mock.calls[0][0] as string
      const decoded = decodeURIComponent(calledUrl)
      expect(decoded).toContain('filter[name][operator]=STARTS_WITH')
      expect(decoded).toContain('filter[name][value]=jo')
      expect(decoded).toContain('page[limit]=10')
    })

    it('returns empty array for queries shorter than 2 chars', async () => {
      const fetchFn = vi.fn()
      const adapter = makeAdapter(fetchFn)
      const result = await adapter.search('user', 'name', 'j')
      expect(result).toEqual([])
      expect(fetchFn).not.toHaveBeenCalled()
    })
  })

  describe('error handling', () => {
    it('throws TransportError on 404', async () => {
      const fetchFn = mockFetchResponse(
        { errors: [{ status: '404', title: 'Not Found' }] },
        404,
      )
      const adapter = makeAdapter(fetchFn)
      await expect(adapter.get('node', '999')).rejects.toThrow(TransportError)
      await expect(adapter.get('node', '999')).rejects.toMatchObject({ status: 404 })
    })

    it('throws TransportError on 422', async () => {
      const fetchFn = mockFetchResponse(
        { errors: [{ status: '422', title: 'Unprocessable', detail: 'Title required' }] },
        422,
      )
      const adapter = makeAdapter(fetchFn)
      await expect(adapter.create('node', {})).rejects.toThrow(TransportError)
    })
  })

  describe('tenant header', () => {
    it('does NOT send X-Tenant-Id when scopingStrategy is server', async () => {
      const fetchFn = mockFetchResponse({ data: [] })
      const adapter = makeAdapter(fetchFn)
      await adapter.list('node')
      const headers = fetchFn.mock.calls[0][1].headers
      expect(headers['X-Tenant-Id']).toBeUndefined()
    })

    it('sends X-Tenant-Id when scopingStrategy is header', async () => {
      const fetchFn = mockFetchResponse({ data: [] })
      const adapter = new JsonApiTransportAdapter(
        '/api',
        { id: 'tenant-42', name: 'Acme', scopingStrategy: 'header' },
        fetchFn,
      )
      await adapter.list('node')
      const headers = fetchFn.mock.calls[0][1].headers
      expect(headers['X-Tenant-Id']).toBe('tenant-42')
    })
  })
})
```

- [ ] **Step 6: Run test to verify it fails**

Run: `cd packages/admin && npx vitest run tests/unit/adapters/JsonApiTransportAdapter.test.ts`
Expected: FAIL — module `~/adapters/JsonApiTransportAdapter` not found.

- [ ] **Step 7: Implement JsonApiTransportAdapter**

```typescript
// packages/admin/app/adapters/JsonApiTransportAdapter.ts
import type { TransportAdapter, ListQuery, ListResult, EntityResource } from '../contracts/transport'
import { TransportError } from '../contracts/transport'
import type { EntitySchema } from '../contracts/schema'
import type { AdminTenant } from '../contracts/auth'

export class JsonApiTransportAdapter implements TransportAdapter {
  constructor(
    private readonly apiPath: string,
    private readonly tenant: AdminTenant,
    private readonly fetchFn: typeof fetch = fetch,
  ) {}

  async list(type: string, query?: ListQuery): Promise<ListResult> {
    const params = new URLSearchParams()
    if (query?.page) {
      params.set('page[offset]', String(query.page.offset))
      params.set('page[limit]', String(query.page.limit))
    }
    if (query?.sort) {
      params.set('sort', query.sort)
    }
    if (query?.filter) {
      for (const [field, cond] of Object.entries(query.filter)) {
        params.set(`filter[${field}][operator]`, cond.operator)
        params.set(`filter[${field}][value]`, cond.value)
      }
    }
    const qs = params.toString()
    const url = `${this.apiPath}/${type}${qs ? '?' + qs : ''}`
    const json = await this.request(url, { method: 'GET' })
    const data = (Array.isArray(json.data) ? json.data : []).map(this.normalizeResource)
    return {
      data,
      meta: {
        total: json.meta?.total ?? 0,
        offset: json.meta?.offset ?? 0,
        limit: json.meta?.limit ?? 25,
      },
    }
  }

  async get(type: string, id: string): Promise<EntityResource> {
    const json = await this.request(`${this.apiPath}/${type}/${id}`, { method: 'GET' })
    return this.normalizeResource(json.data)
  }

  async create(type: string, attributes: Record<string, any>): Promise<EntityResource> {
    const json = await this.request(`${this.apiPath}/${type}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: JSON.stringify({ data: { type, attributes } }),
    })
    return this.normalizeResource(json.data)
  }

  async update(type: string, id: string, attributes: Record<string, any>): Promise<EntityResource> {
    const json = await this.request(`${this.apiPath}/${type}/${id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: JSON.stringify({ data: { type, id, attributes } }),
    })
    return this.normalizeResource(json.data)
  }

  async remove(type: string, id: string): Promise<void> {
    await this.request(`${this.apiPath}/${type}/${id}`, { method: 'DELETE' })
  }

  async schema(type: string): Promise<EntitySchema> {
    const json = await this.request(`${this.apiPath}/schema/${type}`, { method: 'GET' })
    return json.meta.schema
  }

  async search(type: string, field: string, query: string, limit: number = 10): Promise<EntityResource[]> {
    if (query.length < 2) return []
    const params = new URLSearchParams()
    params.set(`filter[${field}][operator]`, 'STARTS_WITH')
    params.set(`filter[${field}][value]`, query)
    params.set('page[limit]', String(limit))
    params.set('sort', field)
    const url = `${this.apiPath}/${type}?${params.toString()}`
    const json = await this.request(url, { method: 'GET' })
    return (Array.isArray(json.data) ? json.data : []).map(this.normalizeResource)
  }

  private async request(url: string, init: RequestInit): Promise<any> {
    const headers: Record<string, string> = {
      Accept: 'application/vnd.api+json',
      ...(init.headers as Record<string, string> ?? {}),
    }
    if (this.tenant.scopingStrategy === 'header') {
      headers['X-Tenant-Id'] = this.tenant.id
    }
    const response = await this.fetchFn(url, { ...init, headers })
    if (response.status === 204) return null
    const json = await response.json()
    if (!response.ok) {
      const error = json.errors?.[0] ?? {}
      throw new TransportError(
        response.status,
        error.title ?? `HTTP ${response.status}`,
        error.detail,
        error.source,
      )
    }
    return json
  }

  private normalizeResource(resource: any): EntityResource {
    return {
      type: resource.type,
      id: resource.id,
      attributes: resource.attributes ?? {},
    }
  }
}
```

- [ ] **Step 8: Create `adapters/index.ts`**

```typescript
// packages/admin/app/adapters/index.ts
export { BootstrapAuthAdapter } from './BootstrapAuthAdapter'
export { JsonApiTransportAdapter } from './JsonApiTransportAdapter'
```

- [ ] **Step 9: Run adapter tests**

Run: `cd packages/admin && npx vitest run tests/unit/adapters/`
Expected: All tests PASS.

- [ ] **Step 10: Commit**

```bash
git add packages/admin/app/adapters/ packages/admin/tests/unit/adapters/
git commit -m "feat(admin): add default BootstrapAuthAdapter and JsonApiTransportAdapter

BootstrapAuthAdapter returns session from bootstrap payload (no network).
JsonApiTransportAdapter speaks JSON:API, normalizes to EntityResource."
```

---

### Task 3: Create Nuxt plugin for bootstrap resolution

**Files:**
- Create: `packages/admin/app/plugins/admin.ts`

- [ ] **Step 1: Create the admin plugin**

```typescript
// packages/admin/app/plugins/admin.ts
import { BootstrapAuthAdapter } from '../adapters/BootstrapAuthAdapter'
import { JsonApiTransportAdapter } from '../adapters/JsonApiTransportAdapter'
import { ADMIN_CONTRACT_VERSION } from '../contracts/version'
import type { AdminBootstrap } from '../contracts/bootstrap'
import type { AdminRuntime } from '../contracts/runtime'

export default defineNuxtPlugin(async () => {
  const config = useRuntimeConfig()
  const baseUrl = (config.public.baseUrl as string) || ''

  // Step 1: Resolve bootstrap — inline first, endpoint fallback
  let bootstrap: AdminBootstrap

  if (import.meta.client && window.__WAASEYAA_ADMIN__) {
    bootstrap = window.__WAASEYAA_ADMIN__
  } else {
    const response = await $fetch<AdminBootstrap>(`${baseUrl}/bootstrap`, {
      ignoreResponseError: true,
      onResponseError({ response: res }) {
        if (res.status === 401) {
          // Will be handled below after version check attempt
        }
      },
    }).catch(() => null)

    if (!response) {
      // No bootstrap available — redirect to a default login or show error
      if (import.meta.client) {
        window.location.href = `${baseUrl}/login`
      }
      // Return a stub to prevent plugin crash during SSR
      return { provide: { admin: null } }
    }
    bootstrap = response
  }

  // Step 2: Validate contract version
  if (bootstrap.version !== ADMIN_CONTRACT_VERSION) {
    throw createError({
      statusCode: 500,
      message: `Admin contract version mismatch: expected ${ADMIN_CONTRACT_VERSION}, got ${bootstrap.version}`,
      fatal: true,
    })
  }

  // Step 3: Instantiate adapters
  const auth = new BootstrapAuthAdapter(bootstrap)
  const apiPath = bootstrap.transport.apiPath ?? '/api'
  const resolvedApiPath = `${baseUrl}${apiPath}`
  const transport = new JsonApiTransportAdapter(resolvedApiPath, bootstrap.tenant)

  // Step 4: Build runtime
  const runtime: AdminRuntime = {
    bootstrap,
    auth,
    transport,
    catalog: bootstrap.entities,
    tenant: bootstrap.tenant,
  }

  return { provide: { admin: runtime } }
})
```

- [ ] **Step 2: Update `nuxt.config.ts` to add baseUrl runtime config**

Add `baseUrl` to `runtimeConfig.public` in `packages/admin/nuxt.config.ts`:

In the `runtimeConfig.public` section, add:

```typescript
baseUrl: process.env.NUXT_PUBLIC_BASE_URL ?? '',
```

Also add `app.baseURL` to support subpath mounting:

```typescript
app: {
  baseURL: process.env.NUXT_PUBLIC_BASE_URL ?? '/',
  // ... existing head config
},
```

- [ ] **Step 3: Add plugin unit test**

Create `packages/admin/tests/unit/plugins/admin.test.ts`. Since the plugin is a Nuxt plugin (depends on `defineNuxtPlugin`, `$fetch`, `useRuntimeConfig`), test the bootstrap resolution logic by extracting it into a testable function:

```typescript
// packages/admin/tests/unit/plugins/admin.test.ts
import { describe, it, expect } from 'vitest'
import { ADMIN_CONTRACT_VERSION } from '~/contracts'

describe('bootstrap version validation', () => {
  it('accepts matching contract version', () => {
    const bootstrap = { version: ADMIN_CONTRACT_VERSION }
    expect(bootstrap.version).toBe('1.0')
  })

  it('rejects mismatched contract version', () => {
    const bootstrap = { version: '2.0' }
    expect(bootstrap.version).not.toBe(ADMIN_CONTRACT_VERSION)
  })
})
```

Note: Full plugin integration testing (bootstrap endpoint fetch, 401 redirect, inline config) is covered by Playwright E2E tests in Chunk 5. Unit testing the plugin is limited because it depends heavily on Nuxt runtime.

- [ ] **Step 4: Verify plugin compiles**

Run: `cd packages/admin && npx nuxi typecheck`
Expected: No type errors.

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/plugins/admin.ts packages/admin/nuxt.config.ts packages/admin/tests/unit/plugins/
git commit -m "feat(admin): add Nuxt plugin for bootstrap resolution

Resolves config from window.__WAASEYAA_ADMIN__ or GET <baseURL>/bootstrap.
Validates contract version and instantiates default adapters.
Adds NUXT_PUBLIC_BASE_URL for subpath mounting."
```

---

## Chunk 2: Composable Refactoring

### Task 4: Create `useAdmin` composable

**Files:**
- Create: `packages/admin/app/composables/useAdmin.ts`
- Test: `packages/admin/tests/unit/composables/useAdmin.test.ts`

- [ ] **Step 1: Write failing test**

```typescript
// packages/admin/tests/unit/composables/useAdmin.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock useNuxtApp to provide $admin
const mockRuntime = {
  bootstrap: {
    version: '1.0',
    auth: { strategy: 'redirect' as const, loginUrl: '/login' },
    account: { id: '1', name: 'Admin', roles: ['admin'] },
    tenant: { id: 'default', name: 'Test', scopingStrategy: 'server' as const },
    transport: { strategy: 'jsonapi' as const, apiPath: '/api' },
    entities: [
      { id: 'node', label: 'Content', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true } },
      { id: 'user', label: 'User', capabilities: { list: true, get: true, create: false, update: false, delete: false, schema: true } },
    ],
  },
  auth: {},
  transport: {},
  catalog: [
    { id: 'node', label: 'Content', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true } },
    { id: 'user', label: 'User', capabilities: { list: true, get: true, create: false, update: false, delete: false, schema: true } },
  ],
  tenant: { id: 'default', name: 'Test', scopingStrategy: 'server' as const },
}

vi.stubGlobal('useNuxtApp', () => ({ $admin: mockRuntime }))

import { useAdmin } from '~/composables/useAdmin'

describe('useAdmin', () => {
  it('returns catalog from runtime', () => {
    const { catalog } = useAdmin()
    expect(catalog).toHaveLength(2)
    expect(catalog[0].id).toBe('node')
  })

  it('returns tenant from runtime', () => {
    const { tenant } = useAdmin()
    expect(tenant.name).toBe('Test')
  })

  it('hasCapability returns true for existing capability', () => {
    const { hasCapability } = useAdmin()
    expect(hasCapability('node', 'create')).toBe(true)
  })

  it('hasCapability returns false for missing capability', () => {
    const { hasCapability } = useAdmin()
    expect(hasCapability('user', 'create')).toBe(false)
  })

  it('hasCapability returns false for unknown entity type', () => {
    const { hasCapability } = useAdmin()
    expect(hasCapability('nonexistent', 'list')).toBe(false)
  })

  it('getEntity returns CatalogEntry by type id', () => {
    const { getEntity } = useAdmin()
    expect(getEntity('node')?.label).toBe('Content')
  })

  it('getEntity returns undefined for unknown type', () => {
    const { getEntity } = useAdmin()
    expect(getEntity('nonexistent')).toBeUndefined()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/admin && npx vitest run tests/unit/composables/useAdmin.test.ts`
Expected: FAIL — module `~/composables/useAdmin` not found.

- [ ] **Step 3: Implement useAdmin**

```typescript
// packages/admin/app/composables/useAdmin.ts
import type { AdminRuntime } from '../contracts/runtime'
import type { CatalogEntry, CatalogCapabilities } from '../contracts/catalog'
import type { AdminTenant } from '../contracts/auth'
import type { AdminBootstrap } from '../contracts/bootstrap'

export function useAdmin(): {
  bootstrap: AdminBootstrap
  catalog: CatalogEntry[]
  tenant: AdminTenant
  hasCapability: (entityType: string, cap: keyof CatalogCapabilities) => boolean
  getEntity: (type: string) => CatalogEntry | undefined
} {
  const { $admin } = useNuxtApp() as { $admin: AdminRuntime }

  function hasCapability(entityType: string, cap: keyof CatalogCapabilities): boolean {
    const entry = $admin.catalog.find(e => e.id === entityType)
    return entry?.capabilities[cap] ?? false
  }

  function getEntity(type: string): CatalogEntry | undefined {
    return $admin.catalog.find(e => e.id === type)
  }

  return {
    bootstrap: $admin.bootstrap,
    catalog: $admin.catalog,
    tenant: $admin.tenant,
    hasCapability,
    getEntity,
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/admin && npx vitest run tests/unit/composables/useAdmin.test.ts`
Expected: All 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/composables/useAdmin.ts packages/admin/tests/unit/composables/useAdmin.test.ts
git commit -m "feat(admin): add useAdmin composable

Provides catalog, tenant, hasCapability(), getEntity() from AdminRuntime."
```

---

### Task 5: Refactor `useEntity` to use TransportAdapter

**Files:**
- Modify: `packages/admin/app/composables/useEntity.ts`
- Modify: `packages/admin/tests/unit/composables/useEntity.test.ts`

- [ ] **Step 1: Update tests to mock `$admin.transport` instead of `$fetch`**

Replace the entire test file. The key change: instead of mocking `$fetch` globally, tests mock `useNuxtApp().$admin.transport` with a fake `TransportAdapter`.

```typescript
// packages/admin/tests/unit/composables/useEntity.test.ts
import { describe, it, expect, vi } from 'vitest'
import type { TransportAdapter, EntityResource, ListResult } from '~/contracts'

const mockTransport: TransportAdapter = {
  list: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
  schema: vi.fn(),
  search: vi.fn(),
}

vi.stubGlobal('useNuxtApp', () => ({
  $admin: { transport: mockTransport },
}))

import { useEntity } from '~/composables/useEntity'

describe('useEntity (adapter-backed)', () => {
  it('list delegates to transport.list', async () => {
    const expected: ListResult = { data: [], meta: { total: 0, offset: 0, limit: 25 } }
    vi.mocked(mockTransport.list).mockResolvedValue(expected)
    const { list } = useEntity()
    const result = await list('node', { page: { offset: 0, limit: 25 } })
    expect(mockTransport.list).toHaveBeenCalledWith('node', { page: { offset: 0, limit: 25 } })
    expect(result).toEqual(expected)
  })

  it('get delegates to transport.get', async () => {
    const resource: EntityResource = { type: 'node', id: '5', attributes: { title: 'Post' } }
    vi.mocked(mockTransport.get).mockResolvedValue(resource)
    const { get } = useEntity()
    const result = await get('node', '5')
    expect(mockTransport.get).toHaveBeenCalledWith('node', '5')
    expect(result).toEqual(resource)
  })

  it('create delegates to transport.create', async () => {
    const resource: EntityResource = { type: 'node', id: '6', attributes: { title: 'New' } }
    vi.mocked(mockTransport.create).mockResolvedValue(resource)
    const { create } = useEntity()
    await create('node', { title: 'New' })
    expect(mockTransport.create).toHaveBeenCalledWith('node', { title: 'New' })
  })

  it('update delegates to transport.update', async () => {
    const resource: EntityResource = { type: 'node', id: '3', attributes: { title: 'Updated' } }
    vi.mocked(mockTransport.update).mockResolvedValue(resource)
    const { update } = useEntity()
    await update('node', '3', { title: 'Updated' })
    expect(mockTransport.update).toHaveBeenCalledWith('node', '3', { title: 'Updated' })
  })

  it('remove delegates to transport.remove', async () => {
    vi.mocked(mockTransport.remove).mockResolvedValue(undefined)
    const { remove } = useEntity()
    await remove('node', '5')
    expect(mockTransport.remove).toHaveBeenCalledWith('node', '5')
  })

  it('search delegates to transport.search', async () => {
    vi.mocked(mockTransport.search).mockResolvedValue([])
    const { search } = useEntity()
    await search('user', 'name', 'jo', 10)
    expect(mockTransport.search).toHaveBeenCalledWith('user', 'name', 'jo', 10)
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/admin && npx vitest run tests/unit/composables/useEntity.test.ts`
Expected: FAIL — `useEntity` still uses `$fetch` directly.

- [ ] **Step 3: Rewrite `useEntity.ts` as thin adapter wrapper**

```typescript
// packages/admin/app/composables/useEntity.ts
import type { AdminRuntime } from '../contracts/runtime'
import type { ListQuery, ListResult, EntityResource } from '../contracts/transport'

export type { EntityResource, ListResult, ListQuery }

// Backward-compatible alias — existing components import JsonApiResource from useEntity.
// This re-export prevents breakage during migration. Remove in a future major version.
export type { EntityResource as JsonApiResource }

export function useEntity() {
  const { $admin } = useNuxtApp() as { $admin: AdminRuntime }
  const transport = $admin.transport

  async function list(type: string, query?: ListQuery): Promise<ListResult> {
    return transport.list(type, query)
  }

  async function get(type: string, id: string): Promise<EntityResource> {
    return transport.get(type, id)
  }

  async function create(type: string, attributes: Record<string, any>): Promise<EntityResource> {
    return transport.create(type, attributes)
  }

  async function update(type: string, id: string, attributes: Record<string, any>): Promise<EntityResource> {
    return transport.update(type, id, attributes)
  }

  async function remove(type: string, id: string): Promise<void> {
    return transport.remove(type, id)
  }

  async function search(type: string, labelField: string, query: string, limit: number = 10): Promise<EntityResource[]> {
    return transport.search(type, labelField, query, limit)
  }

  return { list, get, create, update, remove, search }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/admin && npx vitest run tests/unit/composables/useEntity.test.ts`
Expected: All 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/composables/useEntity.ts packages/admin/tests/unit/composables/useEntity.test.ts
git commit -m "refactor(admin): useEntity delegates to TransportAdapter

Replaces hardcoded \$fetch('/api/{type}') with transport adapter injection.
JSON:API envelope logic now lives in JsonApiTransportAdapter."
```

---

### Task 6: Refactor `useSchema` to use TransportAdapter

**Files:**
- Modify: `packages/admin/app/composables/useSchema.ts`
- Modify: `packages/admin/tests/unit/composables/useSchema.test.ts`

- [ ] **Step 1: Update useSchema to use transport.schema()**

Replace the `$fetch` call in `useSchema.ts`. The composable keeps its caching and `sortedProperties()` logic — only the fetch source changes.

Replace lines 53-56 of `useSchema.ts`:

```typescript
// Before:
const response = await $fetch<{ meta: { schema: EntitySchema } }>(
  `/api/schema/${entityType}`,
)
schema.value = response.meta.schema

// After:
const { $admin } = useNuxtApp() as { $admin: AdminRuntime }
schema.value = await $admin.transport.schema(entityType)
```

Also update the import — remove the `ref` import from `vue` (Nuxt auto-imports it) and add:

```typescript
import type { AdminRuntime } from '../contracts/runtime'
```

Remove the local `SchemaProperty` and `EntitySchema` interfaces — import them from contracts instead:

```typescript
import type { SchemaProperty, EntitySchema } from '../contracts/schema'
export type { SchemaProperty, EntitySchema }
```

- [ ] **Step 2: Update tests to mock `$admin.transport.schema`**

In `tests/unit/composables/useSchema.test.ts`, replace `$fetch` mocking with `$admin.transport` mocking, following the same pattern as Task 5.

- [ ] **Step 3: Run tests**

Run: `cd packages/admin && npx vitest run tests/unit/composables/useSchema.test.ts`
Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/admin/app/composables/useSchema.ts packages/admin/tests/unit/composables/useSchema.test.ts
git commit -m "refactor(admin): useSchema delegates to transport.schema()

Schema types moved to contracts/schema.ts. Caching and sortedProperties()
remain as composable-level UI concerns."
```

---

### Task 7: Refactor `useAuth` to use AuthAdapter

**Files:**
- Modify: `packages/admin/app/composables/useAuth.ts`
- Modify: `packages/admin/tests/unit/composables/useAuth.test.ts`

- [ ] **Step 1: Rewrite `useAuth.ts`**

```typescript
// packages/admin/app/composables/useAuth.ts
import type { AdminRuntime } from '../contracts/runtime'
import type { AdminAccount } from '../contracts/auth'

const STATE_KEY = 'waaseyaa.auth.user'
const CHECKED_KEY = 'waaseyaa.auth.checked'

export function useAuth() {
  const currentUser = useState<AdminAccount | null>(STATE_KEY, () => null)
  const authChecked = useState<boolean>(CHECKED_KEY, () => false)
  const isAuthenticated = computed(() => currentUser.value !== null)

  function getRuntime(): AdminRuntime {
    const { $admin } = useNuxtApp() as { $admin: AdminRuntime }
    return $admin
  }

  async function checkAuth(): Promise<void> {
    if (authChecked.value) return
    const runtime = getRuntime()
    const session = await runtime.auth.getSession()
    currentUser.value = session?.account ?? null
    authChecked.value = true
  }

  async function login(username: string, password: string): Promise<void> {
    const runtime = getRuntime()
    const strategy = runtime.bootstrap.auth.strategy

    if (strategy === 'redirect') {
      const returnTo = window.location.pathname
      window.location.href = runtime.auth.getLoginUrl(returnTo)
      return
    }

    // Embedded strategy — POST to loginEndpoint
    const endpoint = runtime.bootstrap.auth.loginEndpoint
    if (!endpoint) throw new Error('No loginEndpoint configured for embedded auth strategy')

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    })
    if (!response.ok) throw new Error('Login failed')

    const session = await runtime.auth.getSession()
    currentUser.value = session?.account ?? null
  }

  async function logout(): Promise<void> {
    const runtime = getRuntime()
    await runtime.auth.logout()
    currentUser.value = null
    authChecked.value = false
  }

  return { currentUser, isAuthenticated, checkAuth, login, logout }
}
```

Note: `fetchMe()` is removed — `checkAuth()` now uses `auth.getSession()`. The `login()` function handles both strategies. The old `AuthUser` type is replaced by `AdminAccount` from contracts.

- [ ] **Step 2: Update tests**

Rewrite `tests/unit/composables/useAuth.test.ts` to mock `$admin.auth` and `$admin.bootstrap` instead of `$fetch`. Test both redirect and embedded strategies.

- [ ] **Step 3: Run tests**

Run: `cd packages/admin && npx vitest run tests/unit/composables/useAuth.test.ts`
Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/admin/app/composables/useAuth.ts packages/admin/tests/unit/composables/useAuth.test.ts
git commit -m "refactor(admin): useAuth delegates to AuthAdapter

Replaces hardcoded /api/user/me and /api/auth/* with adapter injection.
Supports both redirect and embedded auth strategies."
```

---

### Task 8: Update `useNavGroups` for `CatalogEntry`

**Files:**
- Modify: `packages/admin/app/composables/useNavGroups.ts`
- Modify: `packages/admin/tests/unit/composables/useNavGroups.test.ts`
- Modify: `packages/admin/tests/fixtures/entityTypes.ts`

- [ ] **Step 1: Update `EntityTypeInfo` to `CatalogEntry`**

In `useNavGroups.ts`:
- Remove the `EntityTypeInfo` interface definition (lines 1-7)
- Import `CatalogEntry` from contracts: `import type { CatalogEntry } from '../contracts/catalog'`
- Replace all `EntityTypeInfo` references with `CatalogEntry`
- In `ResolvedNavGroup`, change `entityTypes: NonEmptyArray<EntityTypeInfo>` to `entityTypes: NonEmptyArray<CatalogEntry>`
- Update `groupEntityTypes` signature to accept `CatalogEntry[]`

- [ ] **Step 2: Update test fixtures**

In `tests/fixtures/entityTypes.ts`, add `capabilities` to each entity type fixture:

```typescript
import type { CatalogEntry } from '~/contracts'

const defaultCaps = { list: true, get: true, create: true, update: true, delete: true, schema: true }

export const entityTypes: CatalogEntry[] = [
  { id: 'user', label: 'User', keys: { id: 'id', label: 'name' }, capabilities: defaultCaps },
  { id: 'node', label: 'Content', keys: { id: 'id', label: 'title' }, capabilities: defaultCaps },
  // ... same pattern for all entries
]
```

- [ ] **Step 3: Update useNavGroups tests**

Replace `EntityTypeInfo` references with `CatalogEntry` in the test file.

- [ ] **Step 4: Run tests**

Run: `cd packages/admin && npx vitest run tests/unit/composables/useNavGroups.test.ts`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/composables/useNavGroups.ts packages/admin/tests/unit/composables/useNavGroups.test.ts packages/admin/tests/fixtures/entityTypes.ts
git commit -m "refactor(admin): useNavGroups uses CatalogEntry from contracts

Replaces local EntityTypeInfo interface. keys field is now optional."
```

---

## Chunk 3: Component & Page Updates

### Task 9: Update `NavBuilder.vue` to use catalog from `useAdmin`

**Files:**
- Modify: `packages/admin/app/components/layout/NavBuilder.vue`
- Modify: `packages/admin/tests/components/layout/NavBuilder.test.ts`

- [ ] **Step 1: Remove `/api/entity-types` fetch from NavBuilder**

In `NavBuilder.vue`, replace the `onMounted` fetch of `/api/entity-types` with `useAdmin().catalog`. The component should:
- Call `useAdmin()` to get `catalog`
- Call `groupEntityTypes(catalog)` from `useNavGroups`
- Remove the `$fetch('/api/entity-types')` call
- Remove the loading state (catalog is available synchronously from bootstrap)

- [ ] **Step 2: Update NavBuilder tests**

Mock `useNuxtApp().$admin.catalog` instead of mocking `$fetch('/api/entity-types')`.

- [ ] **Step 3: Run tests**

Run: `cd packages/admin && npx vitest run tests/components/layout/NavBuilder.test.ts`
Expected: Tests PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/admin/app/components/layout/NavBuilder.vue packages/admin/tests/components/layout/NavBuilder.test.ts
git commit -m "refactor(admin): NavBuilder reads catalog from useAdmin()

Removes hardcoded GET /api/entity-types fetch."
```

---

### Task 10: Update dashboard `index.vue` to use catalog from `useAdmin`

**Files:**
- Modify: `packages/admin/app/pages/index.vue`
- Modify: `packages/admin/tests/pages/dashboard.test.ts`

- [ ] **Step 1: Replace `$fetch('/api/entity-types')` with `useAdmin().catalog`**

In `pages/index.vue`, replace the `onMounted` fetch with:

```typescript
const { catalog, hasCapability } = useAdmin()
```

Use `catalog` to render entity type cards. Use `hasCapability` to conditionally show action buttons.

- [ ] **Step 2: Update dashboard tests**

Mock `$admin.catalog` instead of `$fetch`.

- [ ] **Step 3: Run tests**

Run: `cd packages/admin && npx vitest run tests/pages/dashboard.test.ts`
Expected: Tests PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/admin/app/pages/index.vue packages/admin/tests/pages/dashboard.test.ts
git commit -m "refactor(admin): dashboard reads catalog from useAdmin()

Removes hardcoded GET /api/entity-types fetch from dashboard page."
```

---

### Task 11: Update `login.vue` for pluggable auth strategy

**Files:**
- Modify: `packages/admin/app/pages/login.vue`

- [ ] **Step 1: Update login page**

The login page should:
- Check if `$admin` is available (it won't be if bootstrap returned 401 and `strategy === 'embedded'`)
- For embedded strategy: keep the form, but post to `bootstrap.auth.loginEndpoint` instead of `/api/auth/login`
- For redirect strategy: this page should not normally be reached (the plugin redirects), but if it is, redirect to `auth.getLoginUrl()`

Since the plugin handles the 401 → redirect flow for the redirect strategy, the login page only needs to handle the embedded case.

- [ ] **Step 2: Commit**

```bash
git add packages/admin/app/pages/login.vue
git commit -m "refactor(admin): login page uses pluggable auth strategy

Posts to bootstrap.auth.loginEndpoint for embedded strategy.
Redirect strategy is handled by the admin plugin before this page renders."
```

---

### Task 12: Update `[entityType]/index.vue` to remove `/api/entity-types` fetch

**Files:**
- Modify: `packages/admin/app/pages/[entityType]/index.vue`

- [ ] **Step 1: Replace entity type info fetch**

In `pages/[entityType]/index.vue`, the page currently fetches `/api/entity-types` to get the entity type label and metadata. Replace with:

```typescript
const { getEntity, hasCapability } = useAdmin()
const entityInfo = getEntity(route.params.entityType as string)
```

Remove the `/api/entity-types/{id}/disable` and `/api/entity-types/{id}/enable` calls — these are admin-only operations that go through the transport adapter or remain as direct API calls (they're not part of the entity CRUD contract). Keep them as direct `$fetch` calls for now with a `// TODO: move to admin operations adapter` comment.

- [ ] **Step 2: Commit**

```bash
git add packages/admin/app/pages/\\[entityType\\]/index.vue
git commit -m "refactor(admin): entity list page reads type info from useAdmin()

Replaces GET /api/entity-types with useAdmin().getEntity().
Enable/disable operations remain as direct API calls (not in transport contract)."
```

---

### Task 13: Update `SchemaList.vue` for capability gating

**Files:**
- Modify: `packages/admin/app/components/schema/SchemaList.vue`

- [ ] **Step 1: Add capability gating to SchemaList**

In `SchemaList.vue`, import `useAdmin` and use `hasCapability()` to conditionally render:
- "Create" button: only shown when `hasCapability(entityType, 'create')` is true
- "Delete" button per row: only shown when `hasCapability(entityType, 'delete')` is true
- "Edit" link per row: only shown when `hasCapability(entityType, 'update')` is true

```typescript
const { hasCapability } = useAdmin()
const canCreate = hasCapability(props.entityType, 'create')
const canUpdate = hasCapability(props.entityType, 'update')
const canDelete = hasCapability(props.entityType, 'delete')
```

Use these in the template with `v-if` directives.

- [ ] **Step 2: Commit**

```bash
git add packages/admin/app/components/schema/SchemaList.vue
git commit -m "feat(admin): SchemaList gates UI actions on catalog capabilities

Create/edit/delete buttons respect CatalogCapabilities from useAdmin()."
```

---

### Task 14: Update `AdminShell.vue` to show tenant name

**Files:**
- Modify: `packages/admin/app/components/layout/AdminShell.vue`

- [ ] **Step 1: Add tenant name to shell UI**

In `AdminShell.vue`, use `useAdmin().tenant.name` to display the tenant name in the top bar. This replaces any hardcoded app name. Fall back to the existing `appName` runtime config if `$admin` is not yet available (during initial load).

- [ ] **Step 2: Commit**

```bash
git add packages/admin/app/components/layout/AdminShell.vue
git commit -m "refactor(admin): AdminShell shows tenant name from useAdmin()

Falls back to NUXT_PUBLIC_APP_NAME during initial load."
```

---

### Task 15: Run full test suite

**Scope notes:**
- `useCodifiedContext.ts` and telescope pages (`telescope/codified-context/`) are **out of scope** — they keep their hardcoded `/api/telescope/*` endpoints per the spec.
- `pages/[entityType]/create.vue` and `pages/[entityType]/[id].vue` should require **no changes** — they use `useEntity()` and `useSchema()` composables which were refactored to use adapters internally. Verify they still compile.
- Any component importing the old `JsonApiResource` type from `useEntity` will continue to work via the `JsonApiResource` re-export alias added in Task 5.

- [ ] **Step 1: Run all Vitest tests**

Run: `cd packages/admin && npx vitest run`
Expected: All tests PASS. Fix any failures from components that import `EntityTypeInfo` or the old `AuthUser` type.

- [ ] **Step 2: Run TypeScript type checking**

Run: `cd packages/admin && npx nuxi typecheck`
Expected: No type errors.

- [ ] **Step 3: Run build**

Run: `cd packages/admin && npm run build`
Expected: Build succeeds.

- [ ] **Step 4: Commit any remaining fixes**

```bash
git add -u packages/admin/
git commit -m "fix(admin): resolve remaining type and import issues from refactor"
```

---

## Chunk 4: Packaging & PHP Bridge

### Task 16: Update `package.json` for publishing

**Files:**
- Modify: `packages/admin/package.json`

**Note on Nuxt layer path:** The spec proposed `"./nuxt": "./layer/"` with a dedicated `layer/` subdirectory. We use `"./nuxt": "./"` (package root) instead because the existing `app/` structure already follows Nuxt layer conventions. No restructuring needed — downstream Nuxt apps can `extends: ['@waaseyaa/admin/nuxt']` directly. A separate `layer/` directory can be introduced in a future minor version if needed.

- [ ] **Step 1: Remove `"private": true` and add exports**

Update `packages/admin/package.json`:

```json
{
  "name": "@waaseyaa/admin",
  "version": "1.0.0",
  "type": "module",
  "description": "Schema-driven admin SPA for Waaseyaa with pluggable host contracts",
  "exports": {
    ".": {
      "types": "./dist/contracts/index.d.ts",
      "import": "./dist/contracts/index.js"
    },
    "./adapters": {
      "types": "./dist/adapters/index.d.ts",
      "import": "./dist/adapters/index.js"
    },
    "./nuxt": "./"
  },
  "files": [
    "dist/contracts/",
    "dist/adapters/",
    "app/",
    "nuxt.config.ts",
    "tsconfig.json"
  ],
  "scripts": {
    "dev": "nuxt dev",
    "build": "nuxt build",
    "build:contracts": "tsc -p tsconfig.contracts.json",
    "generate": "nuxt generate",
    "preview": "nuxt preview",
    "postinstall": "nuxt prepare",
    "test": "vitest run",
    "test:watch": "vitest",
    "test:coverage": "vitest run --coverage",
    "test:e2e": "playwright test",
    "test:e2e:ui": "playwright test --ui"
  }
}
```

- [ ] **Step 2: Create `tsconfig.contracts.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ES2022",
    "moduleResolution": "bundler",
    "declaration": true,
    "declarationMap": true,
    "outDir": "dist",
    "rootDir": "app",
    "strict": true,
    "skipLibCheck": true
  },
  "include": ["app/contracts/**/*.ts", "app/adapters/**/*.ts"]
}
```

- [ ] **Step 3: Verify contracts build**

Run: `cd packages/admin && npx tsc -p tsconfig.contracts.json`
Expected: `dist/contracts/` and `dist/adapters/` directories created with `.js` and `.d.ts` files.

- [ ] **Step 4: Commit**

```bash
git add packages/admin/package.json packages/admin/tsconfig.contracts.json
git commit -m "feat(admin): configure npm package exports for contracts and adapters

Removes private:true. Adds three export paths:
- '.' for contract types
- './adapters' for default adapter implementations
- './nuxt' for Nuxt layer consumption"
```

---

### Task 17: Create PHP bridge package

**Files:**
- Create: `packages/admin-bridge/composer.json`
- Create: `packages/admin-bridge/src/AdminAccount.php`
- Create: `packages/admin-bridge/src/AdminTenant.php`
- Create: `packages/admin-bridge/src/AdminAuthConfig.php`
- Create: `packages/admin-bridge/src/AdminTransportConfig.php`
- Create: `packages/admin-bridge/src/CatalogCapabilities.php`
- Create: `packages/admin-bridge/src/CatalogEntry.php`
- Create: `packages/admin-bridge/src/AdminBootstrapPayload.php`
- Create: `packages/admin-bridge/src/CatalogBuilder.php`
- Create: `packages/admin-bridge/src/AdminBootstrapController.php`
- Create: `packages/admin-bridge/src/AdminSpaController.php`
- Create: `packages/admin-bridge/src/AdminBridgeServiceProvider.php`
- Test: `packages/admin-bridge/tests/Unit/AdminBootstrapPayloadTest.php`
- Test: `packages/admin-bridge/tests/Unit/CatalogBuilderTest.php`

This is a substantial task. Each PHP class is a small value object or controller. The key files:

- [ ] **Step 1: Create `composer.json`**

```json
{
  "name": "waaseyaa/admin-bridge",
  "description": "PHP bridge for mounting the Waaseyaa Admin SPA",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=8.3"
  },
  "autoload": {
    "psr-4": {
      "Waaseyaa\\AdminBridge\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Waaseyaa\\AdminBridge\\Tests\\": "tests/"
    }
  },
  "extra": {
    "waaseyaa": {
      "providers": [
        "Waaseyaa\\AdminBridge\\AdminBridgeServiceProvider"
      ]
    }
  }
}
```

- [ ] **Step 2: Create value objects**

Create small readonly classes: `AdminAccount`, `AdminTenant`, `AdminAuthConfig`, `AdminTransportConfig`, `CatalogCapabilities`, `CatalogEntry` — each with `toArray(): array`.

`AdminBootstrapPayload` wraps them all and validates contract version:

```php
<?php
declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final readonly class AdminBootstrapPayload
{
    private const CONTRACT_VERSION = '1.0';

    public function __construct(
        public AdminAuthConfig $auth,
        public AdminAccount $account,
        public AdminTenant $tenant,
        public AdminTransportConfig $transport,
        /** @var list<CatalogEntry> */
        public array $entities,
        /** @var array<string, bool> */
        public array $features = [],
        public string $version = self::CONTRACT_VERSION,
    ) {
        if ($this->version !== self::CONTRACT_VERSION) {
            throw new \RuntimeException("Unsupported admin contract version: {$this->version}");
        }
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'auth' => $this->auth->toArray(),
            'account' => $this->account->toArray(),
            'tenant' => $this->tenant->toArray(),
            'transport' => $this->transport->toArray(),
            'entities' => array_map(fn(CatalogEntry $e) => $e->toArray(), $this->entities),
            'features' => $this->features ?: new \stdClass(),
        ];
    }
}
```

- [ ] **Step 3: Create `CatalogBuilder`**

```php
<?php
declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

use Waaseyaa\Entity\EntityTypeManagerInterface;

final class CatalogBuilder
{
    private const DEFAULT_CAPABILITIES = [
        'list' => true, 'get' => true, 'create' => false,
        'update' => false, 'delete' => false, 'schema' => true,
    ];

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /** @return list<CatalogEntry> */
    public function build(): array
    {
        $entries = [];
        foreach ($this->entityTypeManager->getDefinitions() as $definition) {
            $entries[] = new CatalogEntry(
                id: $definition->id(),
                label: $definition->label(),
                capabilities: new CatalogCapabilities(...self::DEFAULT_CAPABILITIES),
            );
        }
        return $entries;
    }
}
```

- [ ] **Step 4: Create controllers**

`AdminBootstrapController` — returns 401 HTTP status for anonymous, `AdminBootstrapPayload::toArray()` as JSON for authenticated:

```php
<?php
declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

use Waaseyaa\Access\AccountInterface;

final class AdminBootstrapController
{
    public function __construct(
        private readonly CatalogBuilder $catalogBuilder,
        private readonly AdminAuthConfig $authConfig,
        private readonly AdminTransportConfig $transportConfig,
        private readonly AdminTenant $tenant,
    ) {}

    public function __invoke(AccountInterface $account): array
    {
        if ($account->isAnonymous()) {
            http_response_code(401);
            return ['error' => 'Unauthorized'];
        }

        $payload = new AdminBootstrapPayload(
            auth: $this->authConfig,
            account: new AdminAccount(
                id: (string) $account->id(),
                name: $account->getAccountName(),
                roles: $account->getRoles(),
            ),
            tenant: $this->tenant,
            transport: $this->transportConfig,
            entities: $this->catalogBuilder->build(),
        );

        return $payload->toArray();
    }
}
```

`AdminSpaController` — serves `index.html` with correct headers:

```php
<?php
declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final class AdminSpaController
{
    public function __construct(
        private readonly string $adminPath,
    ) {}

    public function __invoke(): string
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        return file_get_contents($this->adminPath . '/index.html');
    }
}
```

- [ ] **Step 5: Create `AdminBridgeServiceProvider`**

```php
<?php
declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

use Waaseyaa\Foundation\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;

final class AdminBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register CatalogBuilder
        $this->container->singleton(CatalogBuilder::class, fn($c) =>
            new CatalogBuilder($c->get(\Waaseyaa\Entity\EntityTypeManagerInterface::class))
        );
    }

    public function boot(): void
    {
        $router = $this->container->get(\Waaseyaa\Routing\WaaseyaaRouter::class);
        $adminPath = $this->container->get('admin.spa.path'); // path to extracted tarball

        // Bootstrap endpoint
        $router->addRoute('admin.bootstrap', RouteBuilder::create('/admin/bootstrap')
            ->controller(AdminBootstrapController::class . '::__invoke')
            ->methods('GET')
            ->build());

        // SPA catch-all (must be registered last)
        $router->addRoute('admin.spa', RouteBuilder::create('/admin/{path}')
            ->controller(AdminSpaController::class . '::__invoke')
            ->methods('GET')
            ->requirement('path', '.*')
            ->build());
    }
}
```

- [ ] **Step 6: Write PHPUnit tests**

```php
// packages/admin-bridge/tests/Unit/AdminBootstrapPayloadTest.php
#[Test]
public function payload_serializes_to_valid_contract(): void
{
    $payload = new AdminBootstrapPayload(
        auth: new AdminAuthConfig(strategy: 'redirect', loginUrl: '/login'),
        account: new AdminAccount(id: '1', name: 'Admin', roles: ['admin']),
        tenant: new AdminTenant(id: 'default', name: 'Test'),
        transport: new AdminTransportConfig(strategy: 'jsonapi', apiPath: '/api'),
        entities: [],
    );
    $json = $payload->toArray();
    $this->assertSame('1.0', $json['version']);
    $this->assertSame('redirect', $json['auth']['strategy']);
    $this->assertSame('1', $json['account']['id']);
    $this->assertSame('server', $json['tenant']['scopingStrategy']);
}

#[Test]
public function payload_rejects_invalid_version(): void
{
    $this->expectException(\RuntimeException::class);
    new AdminBootstrapPayload(
        auth: new AdminAuthConfig(strategy: 'redirect'),
        account: new AdminAccount(id: '1', name: 'Test', roles: []),
        tenant: new AdminTenant(id: 'x', name: 'X'),
        transport: new AdminTransportConfig(strategy: 'jsonapi'),
        entities: [],
        version: '2.0',
    );
}
```

- [ ] **Step 7: Run PHP tests**

Run: `./vendor/bin/phpunit packages/admin-bridge/tests/`
Expected: All tests PASS.

- [ ] **Step 8: Commit**

```bash
git add packages/admin-bridge/
git commit -m "feat(admin-bridge): add PHP bridge package for admin SPA hosting

Value objects for AdminBootstrapPayload, CatalogBuilder, controllers.
Validates contract version. Enforces capability defaults."
```

---

## Chunk 5: CI & Contract Validation

### Task 18: Create shared JSON schema for cross-language validation

**Files:**
- Create: `packages/admin/contracts/bootstrap.schema.json`

- [ ] **Step 1: Create the JSON schema**

Write a JSON Schema that describes the `AdminBootstrap` payload shape. Both TypeScript and PHP tests validate against this schema.

- [ ] **Step 2: Commit**

```bash
git add packages/admin/contracts/
git commit -m "feat(admin): add bootstrap.schema.json for cross-language contract validation"
```

---

### Task 19: Create CI workflow

**Files:**
- Create: `.github/workflows/admin.yml`

- [ ] **Step 1: Create the workflow**

Write the GitHub Actions workflow with jobs:
1. **contracts** — TypeScript typecheck + Vitest contract tests + PHP payload tests + JSON schema validation
2. **adapters** — Vitest adapter unit tests
3. **integration** — Playwright smoke tests with mock host (future — create placeholder)
4. **release** (on tag) — contract freeze, tarball integrity, build + publish

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/admin.yml
git commit -m "feat(ci): add admin SPA CI workflow

Contract conformance, adapter tests, integration smoke tests, release pipeline."
```

---

### Task 20: Final verification

- [ ] **Step 1: Run all admin Vitest tests**

Run: `cd packages/admin && npx vitest run`
Expected: All tests PASS.

- [ ] **Step 2: Run TypeScript typecheck**

Run: `cd packages/admin && npx nuxi typecheck`
Expected: No errors.

- [ ] **Step 3: Run build**

Run: `cd packages/admin && npm run build`
Expected: Build succeeds.

- [ ] **Step 4: Run contracts build**

Run: `cd packages/admin && npm run build:contracts`
Expected: `dist/contracts/` and `dist/adapters/` created.

- [ ] **Step 5: Run PHP tests**

Run: `./vendor/bin/phpunit packages/admin-bridge/tests/`
Expected: All tests PASS.

- [ ] **Step 6: Run full repo test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: No regressions.

// packages/admin/tests/unit/composables/useSchema.test.ts
// useSchema delegates to $admin.transport.schema() which calls
// POST /admin/_surface/{type}/action/schema and expects { ok: true, data: EntitySchema }.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import { userSchema } from '../../fixtures/schemas'
import { ADMIN_RUNTIME_UNAVAILABLE_MESSAGE } from '~/composables/useAdminRuntime'

// Register schema endpoints for tests — the transport POSTs to /admin/_surface/{type}/action/schema
registerEndpoint('/admin/_surface/user/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})
registerEndpoint('/admin/_surface/user_fresh/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})
registerEndpoint('/admin/_surface/user_cache/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})
registerEndpoint('/admin/_surface/user_invalidate/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})

// Reset modules before each test so the module-level schemaCache starts fresh.
beforeEach(() => {
  vi.resetModules()
})

afterEach(() => {
  vi.doUnmock('~/composables/useAdminRuntime')
})

describe('sortedProperties', () => {
  it('returns all properties sorted by x-weight when editable=false', async () => {
    const { useSchema } = await import('~/composables/useSchema')
    const { fetch, sortedProperties } = useSchema('user')
    await fetch()
    const props = sortedProperties(false)
    const names = props.map(([name]) => name)
    // uid (-10) before name (0) before email (1) before status (2)
    expect(names).toEqual(['uid', 'name', 'email', 'status'])
  })

  it('excludes system readOnly fields when editable=true', async () => {
    const { useSchema } = await import('~/composables/useSchema')
    const { fetch, sortedProperties } = useSchema('user')
    await fetch()
    const props = sortedProperties(true)
    const names = props.map(([name]) => name)
    // uid is readOnly without x-access-restricted → excluded
    expect(names).not.toContain('uid')
  })

  it('keeps x-access-restricted fields when editable=true', async () => {
    const { useSchema } = await import('~/composables/useSchema')
    const { fetch, sortedProperties } = useSchema('user')
    await fetch()
    const props = sortedProperties(true)
    const names = props.map(([name]) => name)
    // email is readOnly + x-access-restricted → kept (rendered as disabled widget)
    expect(names).toContain('email')
  })
})

describe('useSchema fetch and caching', () => {
  it('forwards bundle scopes and caches each bundle-specific schema separately', async () => {
    const mockSchemaFn = vi.fn().mockImplementation((_type: string, scope?: { bundle?: string }) =>
      Promise.resolve({
        ...userSchema,
        title: scope?.bundle === 'page' ? 'Page' : 'Post',
      }),
    )
    vi.doMock('~/composables/useAdminRuntime', () => ({
      ADMIN_RUNTIME_UNAVAILABLE_MESSAGE,
      requireAdminRuntime: () => ({ transport: { schema: mockSchemaFn } }),
    }))

    const { useSchema } = await import('~/composables/useSchema')
    const composable = useSchema('node_bundle_scopes')

    await composable.fetch({ bundle: 'page' })
    expect(composable.schema.value?.title).toBe('Page')

    await composable.fetch({ bundle: 'post' })
    expect(composable.schema.value?.title).toBe('Post')
    expect(mockSchemaFn).toHaveBeenNthCalledWith(1, 'node_bundle_scopes', { bundle: 'page' })
    expect(mockSchemaFn).toHaveBeenNthCalledWith(2, 'node_bundle_scopes', { bundle: 'post' })

    await composable.fetch({ bundle: 'page' })
    expect(composable.schema.value?.title).toBe('Page')
    expect(mockSchemaFn).toHaveBeenCalledTimes(2)
  })

  it('sets schema.value on successful fetch', async () => {
    const { useSchema } = await import('~/composables/useSchema')
    const { schema, fetch } = useSchema('user_fresh')
    await fetch()
    expect(schema.value?.title).toBe('User')
  })

  it('does not fetch a second time for the same entity type (cache)', async () => {
    const { useSchema } = await import('~/composables/useSchema')
    const instance = useSchema('user_cache')
    await instance.fetch()
    const firstTitle = instance.schema.value?.title
    await instance.fetch()
    expect(instance.schema.value?.title).toBe(firstTitle)
  })

  it('sets error.value when schema fetch fails', async () => {
    registerEndpoint('/admin/_surface/user_error/action/schema', {
      method: 'POST',
      handler: () => {
        throw createError({ statusCode: 500, statusMessage: 'Server Error' })
      },
    })
    const { useSchema } = await import('~/composables/useSchema')
    const { error, fetch } = useSchema('user_error')
    await fetch()
    expect(error.value).toBeTruthy()
  })

  it('clears cache after invalidate()', async () => {
    const { useSchema } = await import('~/composables/useSchema')
    const instance = useSchema('user_invalidate')
    await instance.fetch()
    instance.invalidate()
    // After invalidation, schema should still be loadable
    await instance.fetch()
    expect(instance.schema.value?.title).toBe('User')
  })

  it('records the explicit runtime invariant error when admin runtime is unavailable', async () => {
    vi.doMock('~/composables/useAdminRuntime', () => ({
      ADMIN_RUNTIME_UNAVAILABLE_MESSAGE,
      requireAdminRuntime: () => {
        throw new Error(ADMIN_RUNTIME_UNAVAILABLE_MESSAGE)
      },
    }))

    const { useSchema } = await import('~/composables/useSchema')
    const { error, fetch } = useSchema('user')
    await fetch()
    expect(error.value).toBe(ADMIN_RUNTIME_UNAVAILABLE_MESSAGE)
  })
})

describe('useSchema() in-flight deduplication', () => {
  // These tests mock requireAdminRuntime to control Promise resolution timing precisely.

  it('doesNotDuplicateConcurrentFetches: two concurrent fetch() calls issue exactly one HTTP request', async () => {
    let resolveSchema!: (s: object) => void
    const mockSchemaFn = vi.fn().mockImplementation(
      () => new Promise((resolve) => { resolveSchema = resolve }),
    )
    vi.doMock('~/composables/useAdminRuntime', () => ({
      ADMIN_RUNTIME_UNAVAILABLE_MESSAGE,
      requireAdminRuntime: () => ({ transport: { schema: mockSchemaFn } }),
    }))

    const { useSchema } = await import('~/composables/useSchema')
    const composable = useSchema('dedup_node')

    const p1 = composable.fetch()
    const p2 = composable.fetch()

    resolveSchema(userSchema)
    await Promise.all([p1, p2])

    // FR-008: exactly one HTTP request despite two concurrent callers
    expect(mockSchemaFn).toHaveBeenCalledTimes(1)
  })

  it('clearsInflightOnInvalidate: invalidate() during in-flight causes next fetch() to issue a new request', async () => {
    let callCount = 0
    let resolveFirst!: (s: object) => void
    const mockSchemaFn = vi.fn().mockImplementation(
      () => new Promise((resolve) => {
        callCount++
        resolveFirst = resolve
      }),
    )
    vi.doMock('~/composables/useAdminRuntime', () => ({
      ADMIN_RUNTIME_UNAVAILABLE_MESSAGE,
      requireAdminRuntime: () => ({ transport: { schema: mockSchemaFn } }),
    }))

    const { useSchema } = await import('~/composables/useSchema')
    const composable = useSchema('invalidate_node')

    const _p1 = composable.fetch() // first request in-flight
    composable.invalidate() // FR-003: clear mid-flight
    const _p2 = composable.fetch() // FR-009: should issue second request

    // Two requests: one before invalidate, one after
    expect(callCount).toBe(2)

    // Resolve to avoid unhandled promise rejections
    resolveFirst(userSchema)
  })

  it('doesNotPoisonOnRejection: a rejected fetch() does not prevent a subsequent fresh request', async () => {
    let rejectFirst!: (err: Error) => void
    let resolveSecond!: (s: object) => void
    let callCount = 0
    const mockSchemaFn = vi.fn().mockImplementation(
      () => new Promise((resolve, reject) => {
        callCount++
        if (callCount === 1) { rejectFirst = reject }
        else { resolveSecond = resolve }
      }),
    )
    vi.doMock('~/composables/useAdminRuntime', () => ({
      ADMIN_RUNTIME_UNAVAILABLE_MESSAGE,
      requireAdminRuntime: () => ({ transport: { schema: mockSchemaFn } }),
    }))

    const { useSchema } = await import('~/composables/useSchema')
    const composable = useSchema('rejection_node')

    const p1 = composable.fetch()
    rejectFirst(new Error('network error'))
    // Composable catches error internally — p1 resolves (void), error.value is set
    await p1

    // FR-002: inflightCache cleared on rejection — next fetch issues a fresh request
    const p2 = composable.fetch()
    resolveSecond(userSchema)
    await p2

    // FR-010: two requests (first failed, second succeeded)
    expect(callCount).toBe(2)
    expect(composable.error.value).toBeNull()
  })

  it('clears a stale scoped failure before returning a successfully cached schema', async () => {
    const mockSchemaFn = vi.fn().mockImplementation((_type: string, scope?: { bundle?: string }) => {
      if (scope?.bundle === 'page') {
        return Promise.reject(Object.assign(new Error('schema unavailable'), { status: 500 }))
      }
      return Promise.resolve({ ...userSchema, title: 'Post' })
    })
    vi.doMock('~/composables/useAdminRuntime', () => ({
      ADMIN_RUNTIME_UNAVAILABLE_MESSAGE,
      requireAdminRuntime: () => ({ transport: { schema: mockSchemaFn } }),
    }))

    const { useSchema } = await import('~/composables/useSchema')
    const composable = useSchema('stale_cached_scope')

    await composable.fetch({ bundle: 'post' })
    expect(composable.schema.value?.title).toBe('Post')
    expect(composable.error.value).toBeNull()
    expect(composable.failure.value).toBeNull()

    await composable.fetch({ bundle: 'page' })
    expect(composable.error.value).toBeTruthy()
    expect(composable.failure.value).toMatchObject({ kind: 'server', status: 500 })

    await composable.fetch({ bundle: 'post' })
    expect(mockSchemaFn).toHaveBeenCalledTimes(2)
    expect(composable.schema.value?.title).toBe('Post')
    expect(composable.error.value).toBeNull()
    expect(composable.failure.value).toBeNull()
  })

  it('clears a stale scoped failure before joining an in-flight schema fetch', async () => {
    let resolvePost!: (schema: object) => void
    const mockSchemaFn = vi.fn().mockImplementation((_type: string, scope?: { bundle?: string }) => {
      if (scope?.bundle === 'page') {
        return Promise.reject(Object.assign(new Error('schema unavailable'), { status: 500 }))
      }
      return new Promise((resolve) => { resolvePost = resolve })
    })
    vi.doMock('~/composables/useAdminRuntime', () => ({
      ADMIN_RUNTIME_UNAVAILABLE_MESSAGE,
      requireAdminRuntime: () => ({ transport: { schema: mockSchemaFn } }),
    }))

    const { useSchema } = await import('~/composables/useSchema')
    const failed = useSchema('stale_inflight_scope')
    const live = useSchema('stale_inflight_scope')

    await failed.fetch({ bundle: 'page' })
    expect(failed.failure.value).toMatchObject({ kind: 'server', status: 500 })

    const first = live.fetch({ bundle: 'post' })
    const second = failed.fetch({ bundle: 'post' })
    resolvePost({ ...userSchema, title: 'Post' })
    await Promise.all([first, second])

    expect(mockSchemaFn).toHaveBeenCalledTimes(2)
    expect(failed.schema.value?.title).toBe('Post')
    expect(failed.error.value).toBeNull()
    expect(failed.failure.value).toBeNull()
  })
})

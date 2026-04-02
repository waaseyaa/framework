// packages/admin/tests/unit/composables/useSchema.test.ts
// useSchema delegates to $admin.transport.schema() which calls
// POST /admin/_surface/{type}/action/schema and expects { ok: true, data: EntitySchema }.
import { describe, it, expect, vi, beforeEach } from 'vitest'
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

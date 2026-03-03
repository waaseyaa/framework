// packages/admin/tests/unit/composables/useSchema.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { userSchema } from '../../fixtures/schemas'

// Reset modules before each test so the module-level schemaCache starts fresh.
beforeEach(() => {
  vi.resetModules()
})

describe('sortedProperties', () => {
  it('returns all properties sorted by x-weight when editable=false', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ meta: { schema: userSchema } }))
    const { useSchema } = await import('~/composables/useSchema')
    const { fetch, sortedProperties } = useSchema('user')
    await fetch()
    const props = sortedProperties(false)
    const names = props.map(([name]) => name)
    // uid (-10) before name (0) before email (1) before status (2)
    expect(names).toEqual(['uid', 'name', 'email', 'status'])
  })

  it('excludes system readOnly fields when editable=true', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ meta: { schema: userSchema } }))
    const { useSchema } = await import('~/composables/useSchema')
    const { fetch, sortedProperties } = useSchema('user')
    await fetch()
    const props = sortedProperties(true)
    const names = props.map(([name]) => name)
    // uid is readOnly without x-access-restricted → excluded
    expect(names).not.toContain('uid')
  })

  it('keeps x-access-restricted fields when editable=true', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ meta: { schema: userSchema } }))
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
    const mockFetch = vi.fn().mockResolvedValue({ meta: { schema: userSchema } })
    vi.stubGlobal('$fetch', mockFetch)
    const { useSchema } = await import('~/composables/useSchema')
    const { schema, fetch } = useSchema('user_fresh')
    await fetch()
    expect(schema.value?.title).toBe('User')
  })

  it('does not call $fetch a second time for the same entity type', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ meta: { schema: userSchema } })
    vi.stubGlobal('$fetch', mockFetch)
    const { useSchema } = await import('~/composables/useSchema')
    const instance = useSchema('user_cache')
    await instance.fetch()
    await instance.fetch()
    expect(mockFetch).toHaveBeenCalledTimes(1)
  })

  it('sets error.value when $fetch rejects', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockRejectedValue(new Error('Network failure')))
    const { useSchema } = await import('~/composables/useSchema')
    const { error, fetch } = useSchema('user_error')
    await fetch()
    expect(error.value).toBe('Network failure')
  })

  it('clears cache after invalidate()', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ meta: { schema: userSchema } })
    vi.stubGlobal('$fetch', mockFetch)
    const { useSchema } = await import('~/composables/useSchema')
    const instance = useSchema('user_invalidate')
    await instance.fetch()
    instance.invalidate()
    await instance.fetch()
    expect(mockFetch).toHaveBeenCalledTimes(2)
  })
})

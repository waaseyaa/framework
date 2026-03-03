// packages/admin/tests/unit/composables/useEntity.test.ts
import { describe, it, expect, vi } from 'vitest'
import { useEntity } from '~/composables/useEntity'
import type { JsonApiDocument } from '~/composables/useEntity'

function makeDoc(data: any): JsonApiDocument {
  return { jsonapi: { version: '1.0' }, data }
}

describe('useEntity.search', () => {
  it('returns empty array when query is less than 2 characters', async () => {
    const mockFetch = vi.fn()
    vi.stubGlobal('$fetch', mockFetch)
    const { search } = useEntity()
    expect(await search('user', 'name', '')).toEqual([])
    expect(await search('user', 'name', 'a')).toEqual([])
    expect(mockFetch).not.toHaveBeenCalled()
  })

  it('calls $fetch with correct filter params when query is 2+ chars', async () => {
    const mockFetch = vi.fn().mockResolvedValue(makeDoc([]))
    vi.stubGlobal('$fetch', mockFetch)
    const { search } = useEntity()
    await search('user', 'name', 'jo')
    const calledUrl = mockFetch.mock.calls[0][0] as string
    const decoded = decodeURIComponent(calledUrl)
    expect(decoded).toContain('filter[name][operator]=STARTS_WITH')
    expect(decoded).toContain('filter[name][value]=jo')
  })
})

describe('useEntity.list', () => {
  it('calls /api/:type with no query string when no options given', async () => {
    const mockFetch = vi.fn().mockResolvedValue(makeDoc([]))
    vi.stubGlobal('$fetch', mockFetch)
    const { list } = useEntity()
    await list('node')
    expect(mockFetch).toHaveBeenCalledWith('/api/node')
  })

  it('appends page[offset] and page[limit] from query.page', async () => {
    const mockFetch = vi.fn().mockResolvedValue(makeDoc([]))
    vi.stubGlobal('$fetch', mockFetch)
    const { list } = useEntity()
    await list('node', { page: { offset: 25, limit: 10 } })
    const calledUrl = mockFetch.mock.calls[0][0] as string
    const decoded = decodeURIComponent(calledUrl)
    expect(decoded).toContain('page[offset]=25')
    expect(decoded).toContain('page[limit]=10')
  })

  it('returns data array and meta from response', async () => {
    const resource = { type: 'node', id: '1', attributes: { title: 'Hello' } }
    const mockFetch = vi.fn().mockResolvedValue({
      jsonapi: { version: '1.0' },
      data: [resource],
      meta: { total: 1 },
      links: {},
    })
    vi.stubGlobal('$fetch', mockFetch)
    const { list } = useEntity()
    const result = await list('node')
    expect(result.data).toEqual([resource])
    expect(result.meta).toEqual({ total: 1 })
  })
})

describe('useEntity.create', () => {
  it('sends POST with JSON:API body structure', async () => {
    const resource = { type: 'node', id: '1', attributes: { title: 'New' } }
    const mockFetch = vi.fn().mockResolvedValue(makeDoc(resource))
    vi.stubGlobal('$fetch', mockFetch)
    const { create } = useEntity()
    await create('node', { title: 'New' })
    expect(mockFetch).toHaveBeenCalledWith('/api/node', expect.objectContaining({
      method: 'POST',
      body: { data: { type: 'node', attributes: { title: 'New' } } },
    }))
  })
})

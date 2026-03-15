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

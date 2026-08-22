import { describe, expect, it, vi } from 'vitest'
import { AdminSurfaceTransportAdapter } from '~/adapters/AdminSurfaceTransportAdapter'

describe('AdminSurfaceTransportAdapter', () => {
  it('serializes an explicit bundle when requesting a create schema', async () => {
    const schema = {
      $schema: 'https://json-schema.org/draft-07/schema#',
      title: 'Page',
      description: 'Page schema',
      type: 'object',
      'x-entity-type': 'node',
      'x-translatable': false,
      'x-revisionable': false,
      'x-bundle-key': 'type',
      properties: {},
    }
    const fetchFn = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ ok: true, data: schema }),
    })
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await adapter.schema('node', { bundle: 'page' })

    expect(fetchFn).toHaveBeenCalledWith(
      '/admin/_surface/node/action/schema',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ bundle: 'page' }),
        credentials: 'include',
      }),
    )
  })

  it('serializes the selected bundle key and value in create attributes', async () => {
    const fetchFn = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        ok: true,
        data: { type: 'node', id: '7', attributes: { type: 'page', title: 'About' } },
      }),
    })
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await adapter.create('node', { type: 'page', title: 'About' })

    expect(fetchFn).toHaveBeenCalledWith(
      '/admin/_surface/node/action/create',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ attributes: { type: 'page', title: 'About' } }),
        credentials: 'include',
      }),
    )
  })

  it('normalizes surface list responses into transport resources', async () => {
    const fetchFn = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        ok: true,
        data: {
          entities: [
            {
              type: 'user',
              id: '42',
              attributes: { name: 'Admin' },
            },
          ],
          total: 1,
          offset: 0,
          limit: 25,
        },
      }),
    })

    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)
    const result = await adapter.list('user', {
      page: { offset: 0, limit: 25 },
      sort: 'name',
    })

    expect(fetchFn).toHaveBeenCalledWith(
      '/admin/_surface/user?page%5Boffset%5D=0&page%5Blimit%5D=25&sort=name',
      expect.objectContaining({
        method: 'GET',
        credentials: 'include',
      }),
    )
    expect(result.data).toEqual([
      {
        type: 'user',
        id: '42',
        attributes: { name: 'Admin' },
      },
    ])
    expect(result.meta).toEqual({ total: 1, offset: 0, limit: 25 })
  })

  it('carries the observed mutation token through update and delete without exposing it as an attribute', async () => {
    const responses = [
      { type: 'node', id: '7', attributes: { title: 'Observed' }, mutation_token: 'emt1.observed' },
      { type: 'node', id: '7', attributes: { title: 'Updated' }, mutation_token: 'emt1.successor' },
      undefined,
    ]
    const fetchFn = vi.fn().mockImplementation(async () => {
      const data = responses.shift()
      return {
        ok: true,
        status: data === undefined ? 204 : 200,
        json: async () => ({ ok: true, data }),
      }
    })
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await expect(adapter.get('node', '7')).resolves.toEqual({
      type: 'node',
      id: '7',
      attributes: { title: 'Observed' },
    })
    await adapter.update('node', '7', { title: 'Updated' })
    await adapter.remove('node', '7')

    expect(fetchFn).toHaveBeenNthCalledWith(
      2,
      '/admin/_surface/node/action/update',
      expect.objectContaining({
        body: JSON.stringify({
          id: '7',
          attributes: { title: 'Updated' },
          mutation_token: 'emt1.observed',
        }),
      }),
    )
    expect(fetchFn).toHaveBeenNthCalledWith(
      3,
      '/admin/_surface/node/action/delete',
      expect.objectContaining({
        body: JSON.stringify({ id: '7', mutation_token: 'emt1.successor' }),
      }),
    )
  })

  it('deletes with the successor a workflow transition handed over, not the stale read token', async () => {
    const responses = [
      { type: 'node', id: '7', attributes: { title: 'Observed' }, mutation_token: 'emt1.before-transition' },
      undefined,
    ]
    const fetchFn = vi.fn().mockImplementation(async () => {
      const data = responses.shift()
      return { ok: true, status: data === undefined ? 204 : 200, json: async () => ({ ok: true, data }) }
    })
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await adapter.get('node', '7')
    // A transition committed on the canonical /api route returned this successor.
    adapter.adoptMutationToken('node', '7', 'emt1.after-transition')
    await adapter.remove('node', '7')

    expect(fetchFn).toHaveBeenNthCalledWith(
      2,
      '/admin/_surface/node/action/delete',
      expect.objectContaining({
        body: JSON.stringify({ id: '7', mutation_token: 'emt1.after-transition' }),
      }),
    )
  })

  it('refuses the next write locally after the cached validator is forgotten', async () => {
    const fetchFn = vi.fn().mockImplementation(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ ok: true, data: { type: 'node', id: '7', attributes: {}, mutation_token: 'emt1.observed' } }),
    }))
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await adapter.get('node', '7')
    adapter.forgetMutationToken('node', '7')

    await expect(adapter.remove('node', '7')).rejects.toMatchObject({ status: 428 })
    // The read only; no unfenced delete was ever sent.
    expect(fetchFn).toHaveBeenCalledTimes(1)
  })

  it('treats an empty handed-over successor as no validator rather than sending an empty one', async () => {
    const fetchFn = vi.fn().mockImplementation(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ ok: true, data: { type: 'node', id: '7', attributes: {}, mutation_token: 'emt1.observed' } }),
    }))
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await adapter.get('node', '7')
    adapter.adoptMutationToken('node', '7', '')

    await expect(adapter.remove('node', '7')).rejects.toMatchObject({ status: 428 })
    expect(fetchFn).toHaveBeenCalledTimes(1)
  })

  it('scopes a handed-over successor to its own entity', async () => {
    const fetchFn = vi.fn().mockImplementation(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ ok: true, data: { type: 'node', id: '8', attributes: {}, mutation_token: 'emt1.eight' } }),
    }))
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await adapter.get('node', '8')
    adapter.forgetMutationToken('node', '7')
    adapter.adoptMutationToken('node', '7', 'emt1.seven')

    await adapter.remove('node', '8')
    expect(fetchFn).toHaveBeenNthCalledWith(
      2,
      '/admin/_surface/node/action/delete',
      expect.objectContaining({ body: JSON.stringify({ id: '8', mutation_token: 'emt1.eight' }) }),
    )
  })

  it('keys mutation authority by the requested surface when the canonical entity type differs', async () => {
    const responses = [
      { type: 'taxonomy_vocabulary', id: 'topics', attributes: { name: 'Topics' }, mutation_token: 'emt1.observed' },
      { type: 'taxonomy_vocabulary', id: 'topics', attributes: { name: 'Renamed' }, mutation_token: 'emt1.successor' },
    ]
    const fetchFn = vi.fn().mockImplementation(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ ok: true, data: responses.shift() }),
    }))
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await adapter.get('taxonomy_vocabulary_browser', 'topics')
    await adapter.update('taxonomy_vocabulary_browser', 'topics', { name: 'Renamed' })

    expect(fetchFn).toHaveBeenNthCalledWith(
      2,
      '/admin/_surface/taxonomy_vocabulary_browser/action/update',
      expect.objectContaining({
        body: JSON.stringify({
          id: 'topics',
          attributes: { name: 'Renamed' },
          mutation_token: 'emt1.observed',
        }),
      }),
    )
  })

  it('refuses a blind update before making a request', async () => {
    const fetchFn = vi.fn()
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await expect(adapter.update('node', '7', { title: 'Blind write' })).rejects.toMatchObject({
      status: 428,
      title: 'Precondition required',
    })
    await expect(adapter.update('node', '7', { title: 'Blind write' })).rejects.toEqual(
      expect.not.objectContaining({
        code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
      }),
    )
    expect(fetchFn).not.toHaveBeenCalled()
  })

  it('keeps the restore mutation token private and refreshes it from the successor', async () => {
    const responses = [
      { type: 'node', id: '7', attributes: { title: 'Current' }, mutation_token: 'emt1.observed' },
      {
        entityType: 'node', entityId: '7', sourceRevisionId: 1, resultingRevisionId: 4,
        entity: { type: 'node', id: '7', attributes: { title: 'Restored' }, mutation_token: 'emt1.successor' },
      },
      { type: 'node', id: '7', attributes: { title: 'Edited' }, mutation_token: 'emt1.after-edit' },
    ]
    const fetchFn = vi.fn().mockImplementation(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ ok: true, data: responses.shift() }),
    }))
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await adapter.get('node', '7')
    await adapter.runAction('node', 'restore-revision', {
      id: '7', revision_id: 1, expected_latest_revision_id: 3,
    })
    await adapter.update('node', '7', { title: 'Edited' })

    expect(fetchFn).toHaveBeenNthCalledWith(2, '/admin/_surface/node/action/restore-revision', expect.objectContaining({
      body: JSON.stringify({
        id: '7', revision_id: 1, expected_latest_revision_id: 3, mutation_token: 'emt1.observed',
      }),
    }))
    expect(fetchFn).toHaveBeenNthCalledWith(3, '/admin/_surface/node/action/update', expect.objectContaining({
      body: JSON.stringify({ id: '7', attributes: { title: 'Edited' }, mutation_token: 'emt1.successor' }),
    }))
  })

  it('refuses a blind revision restore before making a request', async () => {
    const fetchFn = vi.fn()
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await expect(adapter.runAction('node', 'restore-revision', {
      id: '7', revision_id: 1, expected_latest_revision_id: 3,
    })).rejects.toMatchObject({ status: 428, title: 'Precondition required' })
    expect(fetchFn).not.toHaveBeenCalled()
  })

  it('throws a transport error for failed surface responses', async () => {
    const fetchFn = vi.fn().mockResolvedValue({
      ok: false,
      status: 403,
      json: async () => ({
        ok: false,
        error: {
          status: 403,
          title: 'Forbidden',
          detail: 'Denied',
        },
      }),
    })

    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await expect(adapter.get('user', '42')).rejects.toMatchObject({
      status: 403,
      title: 'Forbidden',
      detail: 'Denied',
    })
  })

  it('propagates acknowledgement tokens and structured advisory errors', async () => {
    const token = 'a'.repeat(64)
    const advisory = {
      code: 'RESERVED_ROUTE_VALUE',
      field: 'title',
      severity: 'warning',
      message: 'Review the fallback URL.',
      acknowledgement: token,
    }
    const fetchFn = vi.fn().mockResolvedValue({
      ok: false,
      status: 428,
      json: async () => ({
        ok: false,
        error: {
          status: 428,
          title: 'Precondition Required',
          detail: 'Review and acknowledge the save advisory before retrying.',
          code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
          meta: { save_advisories: [advisory] },
        },
      }),
    })
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    await expect(adapter.create('node', { title: 'News' }, [token])).rejects.toMatchObject({
      status: 428,
      code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
      meta: { save_advisories: [advisory] },
    })
    expect(fetchFn).toHaveBeenCalledWith(
      '/admin/_surface/node/action/create',
      expect.objectContaining({
        body: JSON.stringify({
          attributes: { title: 'News' },
          save_advisory_acknowledgements: [token],
        }),
      }),
    )
  })

  it('dedupes concurrent GETs of the same record into a single request', async () => {
    let calls = 0
    const fetchFn = vi.fn(async () => {
      calls++
      return {
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { type: 'node', id: '7', attributes: { title: 'A' } } }),
      }
    })
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as unknown as typeof fetch)

    // The detail page mounts a viewer/form and a history widget in the same tick;
    // both read the same record. They must share one network request.
    const [a, b] = await Promise.all([adapter.get('node', '7'), adapter.get('node', '7')])

    expect(calls).toBe(1)
    expect(a).toEqual(b)
    expect(a).toEqual({ type: 'node', id: '7', attributes: { title: 'A' } })

    // A read issued AFTER the first settles must hit the network again — the
    // dedup is in-flight-only, not a persistent cache (no staleness after saves).
    await adapter.get('node', '7')
    expect(calls).toBe(2)
  })

  it('does not share in-flight requests across different records', async () => {
    const fetchFn = vi.fn(async (url: string) => ({
      ok: true,
      status: 200,
      json: async () => ({
        ok: true,
        data: { type: 'node', id: url.includes('/8') ? '8' : '7', attributes: {} },
      }),
    }))
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as unknown as typeof fetch)

    await Promise.all([adapter.get('node', '7'), adapter.get('node', '8')])

    expect(fetchFn).toHaveBeenCalledTimes(2)
  })

  it('the edit-surface GET is the plain admin_surface.get URL, with no working-copy signal — the server decides transparently (CW-v1 option-1 PR-3)', async () => {
    // GenericAdminSurfaceHost::get() (packages/admin-surface) now serves the
    // WORKING COPY to accounts with entity update access unconditionally —
    // "unconditional for editors" rather than a query-param opt-in like
    // JSON:API's `?workingCopy=1` — because this ONE transport call backs
    // both the edit page's "view" and "edit" client-side sub-modes with no
    // per-request signal distinguishing them. This test pins that the SPA
    // requires NO client-side change to receive draft content: the request
    // shape is identical to a plain read, and whatever the backend returns
    // (a draft title here, standing in for a working-copy response) is
    // surfaced as-is.
    const fetchFn = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({
        ok: true,
        data: { type: 'node', id: '7', attributes: { title: 'Forward draft title' } },
      }),
    })
    const adapter = new AdminSurfaceTransportAdapter('/admin/', fetchFn as typeof fetch)

    const result = await adapter.get('node', '7')

    expect(fetchFn).toHaveBeenCalledWith(
      '/admin/_surface/node/7',
      expect.objectContaining({ method: 'GET', credentials: 'include' }),
    )
    expect(result).toEqual({ type: 'node', id: '7', attributes: { title: 'Forward draft title' } })
  })
})

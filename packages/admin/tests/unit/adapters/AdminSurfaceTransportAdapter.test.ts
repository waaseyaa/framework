import { describe, expect, it, vi } from 'vitest'
import { AdminSurfaceTransportAdapter } from '~/adapters/AdminSurfaceTransportAdapter'

describe('AdminSurfaceTransportAdapter', () => {
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

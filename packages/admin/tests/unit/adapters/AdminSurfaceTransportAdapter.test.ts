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

    const adapter = new AdminSurfaceTransportAdapter('/admin/_surface', fetchFn as typeof fetch)
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

    const adapter = new AdminSurfaceTransportAdapter('/admin/_surface', fetchFn as typeof fetch)

    await expect(adapter.get('user', '42')).rejects.toMatchObject({
      status: 403,
      title: 'Forbidden',
      detail: 'Denied',
    })
  })
})

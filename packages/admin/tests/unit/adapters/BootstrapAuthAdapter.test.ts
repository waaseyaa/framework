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

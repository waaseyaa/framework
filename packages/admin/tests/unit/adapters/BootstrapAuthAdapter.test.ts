import { describe, it, expect, vi } from 'vitest'
import { SessionAuthAdapter } from '~/adapters/SessionAuthAdapter'
import type { AdminAuthConfig } from '~/contracts/runtime'

const account = { id: '1', name: 'Admin', email: 'admin@example.com', roles: ['admin'] }
const tenant = { id: 'default', name: 'Test', scopingStrategy: 'server' as const }
const authConfig: AdminAuthConfig = { strategy: 'redirect', loginUrl: '/login' }

describe('SessionAuthAdapter', () => {
  it('getSession returns session from constructor data without network call', async () => {
    const adapter = new SessionAuthAdapter(account, tenant, authConfig)
    const session = await adapter.getSession()
    expect(session).toEqual({
      account,
      tenant,
      features: undefined,
    })
  })

  it('getSession includes features when present', async () => {
    const adapter = new SessionAuthAdapter(account, tenant, authConfig, { darkMode: true })
    const session = await adapter.getSession()
    expect(session!.features).toEqual({ darkMode: true })
  })

  it('logout is a no-op (redirect-based auth)', async () => {
    const adapter = new SessionAuthAdapter(account, tenant, authConfig)
    await adapter.logout()
    // No error means success
  })

  it('getLoginUrl returns loginUrl with returnTo param', () => {
    const adapter = new SessionAuthAdapter(account, tenant, authConfig)
    expect(adapter.getLoginUrl('/admin/dashboard')).toBe('/login?returnTo=%2Fadmin%2Fdashboard')
  })

  it('refreshSession returns null (no-op)', async () => {
    const adapter = new SessionAuthAdapter(account, tenant, authConfig)
    const result = await adapter.refreshSession()
    expect(result).toBeNull()
  })
})

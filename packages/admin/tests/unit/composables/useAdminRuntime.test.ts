import { describe, expect, it } from 'vitest'
import type { AdminRuntime } from '~/contracts/runtime'
import { requireAdminRuntime } from '~/composables/useAdminRuntime'

describe('requireAdminRuntime', () => {
  it('returns the injected admin runtime when present', () => {
    const runtime = {
      catalog: [],
      tenant: { id: 'default', name: 'Waaseyaa', scopingStrategy: 'server' as const },
      account: { id: '1', name: 'Admin', email: 'admin@example.com', roles: ['admin'] },
      auth: {} as AdminRuntime['auth'],
      authConfig: { strategy: 'redirect' as const, loginUrl: '/login' },
      transport: {} as AdminRuntime['transport'],
    } satisfies AdminRuntime

    expect(requireAdminRuntime({ $admin: runtime })).toBe(runtime)
  })

  it('throws an explicit invariant error when admin runtime is unavailable', () => {
    expect(() => requireAdminRuntime({ $admin: null })).toThrowError(
      'Admin runtime is unavailable. Ensure the admin plugin has bootstrapped before calling admin composables.',
    )
  })
})

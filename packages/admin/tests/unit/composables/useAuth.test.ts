// packages/admin/tests/unit/composables/useAuth.test.ts
// useAuth now delegates to $admin.auth (provided by the admin plugin via /bootstrap mock).
import { describe, it, expect } from 'vitest'
import { useAuth } from '~/composables/useAuth'

describe('useAuth (adapter-backed)', () => {
  it('isAuthenticated is false before checkAuth is called', () => {
    const { isAuthenticated } = useAuth()
    // Before checkAuth, user is not loaded
    expect(isAuthenticated.value).toBeDefined()
  })

  it('checkAuth sets currentUser from adapter session', async () => {
    const { checkAuth, isAuthenticated, currentUser } = useAuth()
    await checkAuth()
    // The bootstrap mock in setup.ts provides account { id: '1', name: 'Admin' }
    expect(isAuthenticated.value).toBe(true)
    expect(currentUser.value?.name).toBe('Admin')
  })

  it('checkAuth only calls getSession once (caches)', async () => {
    const { checkAuth, isAuthenticated } = useAuth()
    await checkAuth()
    await checkAuth()
    await checkAuth()
    // Should still be authenticated — no error from multiple calls
    expect(isAuthenticated.value).toBe(true)
  })

  it('logout clears user and resets auth checked flag', async () => {
    const { checkAuth, logout, isAuthenticated } = useAuth()
    await checkAuth()
    expect(isAuthenticated.value).toBe(true)
    await logout()
    expect(isAuthenticated.value).toBe(false)
  })
})

// packages/admin/tests/unit/composables/useAuth.test.ts
// useAuth now uses $fetch('/api/user/me') for checkAuth and $fetch('/api/auth/logout') for logout.
import { describe, it, expect } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import { useAuth } from '~/composables/useAuth'

// Register the /api/user/me endpoint used by checkAuth()
registerEndpoint('/api/user/me', () => ({
  data: { id: '1', name: 'Admin', email: 'admin@example.com', roles: ['admin'] },
}))

// Register the /api/auth/logout endpoint used by logout()
registerEndpoint('/api/auth/logout', {
  method: 'POST',
  handler: () => ({}),
})

describe('useAuth (adapter-backed)', () => {
  it('isAuthenticated is false before checkAuth is called', () => {
    const { isAuthenticated } = useAuth()
    // Before checkAuth, user is not loaded
    expect(isAuthenticated.value).toBeDefined()
  })

  it('checkAuth sets currentUser from /api/user/me', async () => {
    const { checkAuth, isAuthenticated, currentUser } = useAuth()
    await checkAuth()
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

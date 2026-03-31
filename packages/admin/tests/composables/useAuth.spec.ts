import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'

// vi.hoisted runs before all imports — safe to use in mockNuxtImport factories
const { userRef, checkedRef } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    userRef: ref<unknown>(null),
    checkedRef: ref<boolean>(false),
  }
})

mockNuxtImport('useState', () => {
  return <T>(key: string, init: () => T) => {
    if (key === 'waaseyaa.auth.user') return userRef
    if (key === 'waaseyaa.auth.checked') return checkedRef
    const { ref } = require('vue') as typeof import('vue')
    return ref<T>(init())
  }
})

mockNuxtImport('computed', () => {
  const { computed } = require('vue')
  return computed
})

describe('useAuth', () => {
  beforeEach(() => {
    userRef.value = null
    checkedRef.value = false
    vi.unstubAllGlobals()
  })

  describe('login()', () => {
    it('returns success with account when API returns data.id', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({
        data: { id: '42', name: 'Admin User', email: 'admin@example.com', roles: ['administrator'] },
      }))

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'secret')

      expect(result.success).toBe(true)
      expect(result.account).toEqual({
        id: '42',
        name: 'Admin User',
        email: 'admin@example.com',
        roles: ['administrator'],
      })
      expect(userRef.value).toEqual(result.account)
      expect(checkedRef.value).toBe(true)
    })

    it('returns failure with error detail from API errors array', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({
        errors: [{ status: '401', title: 'Unauthorized', detail: 'Bad credentials.' }],
      }))

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'wrong')

      expect(result.success).toBe(false)
      expect(result.error).toBe('Bad credentials.')
      expect(userRef.value).toBeNull()
    })

    it('returns generic failure when API returns no data and no errors', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({}))

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'wrong')

      expect(result.success).toBe(false)
      expect(result.error).toBe('Invalid username or password.')
    })

    it('returns network error message when $fetch throws', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockRejectedValue(new Error('Network error')))

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'secret')

      expect(result.success).toBe(false)
      expect(result.error).toBe('Unable to reach the server. Please try again.')
      expect(userRef.value).toBeNull()
    })

    it('coerces numeric id to string', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({
        data: { id: 1, name: 'Admin', email: 'a@b.com', roles: [] },
      }))

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'secret')

      expect(result.success).toBe(true)
      expect(result.account?.id).toBe('1')
    })
  })

  describe('logout()', () => {
    it('clears currentUser and authChecked after logout', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({}))

      const { useAuth } = await import('~/composables/useAuth')
      const { logout } = useAuth()

      // Seed state as if logged in
      userRef.value = { id: '1', name: 'Admin', roles: ['administrator'] }
      checkedRef.value = true

      await logout()

      expect(userRef.value).toBeNull()
      expect(checkedRef.value).toBe(false)
    })

    it('still clears state even if logout API call throws', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockRejectedValue(new Error('Server error')))

      const { useAuth } = await import('~/composables/useAuth')
      const { logout } = useAuth()

      userRef.value = { id: '1', name: 'Admin', roles: [] }
      checkedRef.value = true

      await logout()

      expect(userRef.value).toBeNull()
      expect(checkedRef.value).toBe(false)
    })
  })

  describe('checkAuth()', () => {
    it('sets currentUser from API response', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({
        data: { id: '5', name: 'Editor', email: 'e@example.com', roles: ['editor'] },
      }))

      const { useAuth } = await import('~/composables/useAuth')
      const { checkAuth } = useAuth()

      await checkAuth()

      expect(userRef.value).toEqual({
        id: '5',
        name: 'Editor',
        email: 'e@example.com',
        roles: ['editor'],
      })
      expect(checkedRef.value).toBe(true)
    })

    it('sets currentUser to null when API returns no id', async () => {
      vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ data: {} }))

      const { useAuth } = await import('~/composables/useAuth')
      const { checkAuth } = useAuth()

      await checkAuth()

      expect(userRef.value).toBeNull()
    })

    it('skips API call if authChecked is already true', async () => {
      const mockFetch = vi.fn()
      vi.stubGlobal('$fetch', mockFetch)

      const { useAuth } = await import('~/composables/useAuth')
      const { checkAuth } = useAuth()

      checkedRef.value = true
      await checkAuth()

      expect(mockFetch).not.toHaveBeenCalled()
    })
  })

  describe('isAuthenticated', () => {
    it('is false when currentUser is null', async () => {
      vi.stubGlobal('$fetch', vi.fn())

      const { useAuth } = await import('~/composables/useAuth')
      const { isAuthenticated } = useAuth()

      expect(isAuthenticated.value).toBe(false)
    })

    it('is true when currentUser is set', async () => {
      vi.stubGlobal('$fetch', vi.fn())

      userRef.value = { id: '1', name: 'Admin', roles: [] }

      const { useAuth } = await import('~/composables/useAuth')
      const { isAuthenticated } = useAuth()

      expect(isAuthenticated.value).toBe(true)
    })
  })
})

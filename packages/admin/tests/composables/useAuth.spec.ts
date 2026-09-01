import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'

// vi.hoisted runs before all imports — safe to use in mockNuxtImport factories
const { userRef, checkedRef, pendingEmailRef, nuxtFetchMock } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    userRef: ref<unknown>(null),
    checkedRef: ref<boolean>(false),
    pendingEmailRef: ref<string>(''),
    nuxtFetchMock: vi.fn(),
  }
})

mockNuxtImport('$fetch', () => nuxtFetchMock)

mockNuxtImport('useState', () => {
  return <T>(key: string, init: () => T) => {
    if (key === 'waaseyaa.auth.user') return userRef
    if (key === 'waaseyaa.auth.checked') return checkedRef
    if (key === 'waaseyaa.auth.pendingVerificationEmail') return pendingEmailRef
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
    pendingEmailRef.value = ''
    nuxtFetchMock.mockReset()
  })

  describe('login()', () => {
    it('returns success with account when API returns data.id', async () => {
      nuxtFetchMock.mockResolvedValue({
        data: { id: '42', name: 'Admin User', email: 'admin@example.com', roles: ['administrator'], emailVerified: true },
      })

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'secret')

      expect(result.success).toBe(true)
      expect(result.account).toEqual({
        id: '42',
        name: 'Admin User',
        email: 'admin@example.com',
        roles: ['administrator'],
        emailVerified: true,
      })
      expect(userRef.value).toEqual(result.account)
      expect(checkedRef.value).toBe(true)
    })

    it('returns failure with error detail from API errors array', async () => {
      nuxtFetchMock.mockResolvedValue({
        errors: [{ status: '401', title: 'Unauthorized', detail: 'Bad credentials.' }],
      })

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'wrong')

      expect(result.success).toBe(false)
      expect(result.error).toBe('Bad credentials.')
      expect(userRef.value).toBeNull()
    })

    it('returns generic failure when API returns no data and no errors', async () => {
      nuxtFetchMock.mockResolvedValue({})

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'wrong')

      expect(result.success).toBe(false)
      expect(result.error).toBe('Invalid username or password.')
    })

    it('returns network error message when $fetch throws', async () => {
      nuxtFetchMock.mockRejectedValue(new Error('Network error'))

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'secret')

      expect(result.success).toBe(false)
      expect(result.error).toBe('Unable to reach the server. Please try again.')
      expect(userRef.value).toBeNull()
    })

    it('coerces numeric id to string', async () => {
      nuxtFetchMock.mockResolvedValue({
        data: { id: 1, name: 'Admin', email: 'a@b.com', roles: [] },
      })

      const { useAuth } = await import('~/composables/useAuth')
      const { login } = useAuth()

      const result = await login('admin', 'secret')

      expect(result.success).toBe(true)
      expect(result.account?.id).toBe('1')
    })
  })

  describe('resendVerification()', () => {
    it('sends an explicit email for the public post-restart flow', async () => {
      nuxtFetchMock.mockResolvedValue({})

      const { useAuth } = await import('~/composables/useAuth')
      const { resendVerification } = useAuth()
      const result = await resendVerification('person@example.com')

      expect(result.ok).toBe(true)
      expect(nuxtFetchMock).toHaveBeenCalledWith('/api/auth/resend-verification', expect.objectContaining({
        method: 'POST',
        body: { email: 'person@example.com' },
      }))
    })

    it('uses the current account email when called from the in-session banner', async () => {
      nuxtFetchMock.mockResolvedValue({})
      userRef.value = { id: '1', name: 'Person', email: 'person@example.com', roles: [], emailVerified: false }

      const { useAuth } = await import('~/composables/useAuth')
      const { resendVerification } = useAuth()
      const result = await resendVerification()

      expect(result.ok).toBe(true)
      expect(nuxtFetchMock).toHaveBeenCalledWith('/api/auth/resend-verification', expect.objectContaining({
        body: { email: 'person@example.com' },
      }))
    })

    it('does not issue a request without an email address', async () => {
      const { useAuth } = await import('~/composables/useAuth')
      const { resendVerification } = useAuth()
      const result = await resendVerification()

      expect(result).toEqual({ ok: false, error: 'Enter the email address used for registration.' })
      expect(nuxtFetchMock).not.toHaveBeenCalled()
    })

    it('treats the backend top-level rate-limit envelope as a failure', async () => {
      nuxtFetchMock.mockResolvedValue({ error: 'too_many_attempts' })

      const { useAuth } = await import('~/composables/useAuth')
      const result = await useAuth().resendVerification('person@example.com')

      expect(result).toEqual({ ok: false, error: 'Failed to resend verification email.' })
    })
  })

  describe('register()', () => {
    it('maps the registration verification state into the admin account contract', async () => {
      nuxtFetchMock.mockResolvedValue({
        data: {
          id: '8',
          name: 'Invited User',
          email: 'invite@example.com',
          roles: ['authenticated'],
          email_verified: true,
        },
      })

      const { useAuth } = await import('~/composables/useAuth')
      const { register } = useAuth()
      const result = await register('Invited User', 'invite@example.com', 'password123', 'invite-token')

      expect(result.success).toBe(true)
      expect(result.account?.emailVerified).toBe(true)
      expect(userRef.value).toEqual(result.account)
    })

    it('does not invent an authenticated account when verification is required', async () => {
      nuxtFetchMock.mockResolvedValue({
        data: {
          id: '9',
          name: 'Open User',
          email: 'open@example.com',
          roles: ['authenticated'],
          email_verified: false,
        },
        meta: { verification_required: true },
      })

      const { useAuth } = await import('~/composables/useAuth')
      const result = await useAuth().register('Open User', 'open@example.com', 'password123')

      expect(result.success).toBe(true)
      expect(result.verificationRequired).toBe(true)
      expect(userRef.value).toBeNull()
      expect(checkedRef.value).toBe(false)
      expect(pendingEmailRef.value).toBe('open@example.com')
    })
  })

  describe('verifyEmail()', () => {
    it('treats the backend top-level invalid-token envelope as a failure', async () => {
      nuxtFetchMock.mockResolvedValue({ error: 'invalid_token' })

      const { useAuth } = await import('~/composables/useAuth')
      const result = await useAuth().verifyEmail('expired-token')

      expect(result).toEqual({ ok: false, error: 'Email verification failed.' })
      expect(userRef.value).toBeNull()
    })
  })

  describe('logout()', () => {
    it('clears currentUser and authChecked after logout', async () => {
      nuxtFetchMock.mockResolvedValue({})

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
      nuxtFetchMock.mockRejectedValue(new Error('Server error'))

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
      nuxtFetchMock.mockResolvedValue({
        data: { id: '5', name: 'Editor', email: 'e@example.com', roles: ['editor'] },
      })

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
      nuxtFetchMock.mockResolvedValue({ data: {} })

      const { useAuth } = await import('~/composables/useAuth')
      const { checkAuth } = useAuth()

      await checkAuth()

      expect(userRef.value).toBeNull()
    })

    it('skips API call if authChecked is already true', async () => {
      const mockFetch = nuxtFetchMock

      const { useAuth } = await import('~/composables/useAuth')
      const { checkAuth } = useAuth()

      checkedRef.value = true
      await checkAuth()

      expect(mockFetch).not.toHaveBeenCalled()
    })
  })

  describe('isAuthenticated', () => {
    it('is false when currentUser is null', async () => {
      nuxtFetchMock.mockResolvedValue(undefined)

      const { useAuth } = await import('~/composables/useAuth')
      const { isAuthenticated } = useAuth()

      expect(isAuthenticated.value).toBe(false)
    })

    it('is true when currentUser is set', async () => {
      nuxtFetchMock.mockResolvedValue(undefined)

      userRef.value = { id: '1', name: 'Admin', roles: [] }

      const { useAuth } = await import('~/composables/useAuth')
      const { isAuthenticated } = useAuth()

      expect(isAuthenticated.value).toBe(true)
    })
  })
})

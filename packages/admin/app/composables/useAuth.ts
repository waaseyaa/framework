import type { AdminAccount } from '../contracts/auth'

export type { AdminAccount }

export interface LoginResult {
  success: boolean
  error?: string
  account?: AdminAccount
}

const STATE_KEY = 'waaseyaa.auth.user'
const CHECKED_KEY = 'waaseyaa.auth.checked'

export function useAuth() {
  const currentUser = useState<AdminAccount | null>(STATE_KEY, () => null)
  const authChecked = useState<boolean>(CHECKED_KEY, () => false)
  const isAuthenticated = computed(() => currentUser.value !== null)

  async function checkAuth(): Promise<void> {
    if (authChecked.value) return
    try {
      const res = await $fetch<{ data?: AdminAccount }>('/api/user/me', {
        baseURL: '/',
        credentials: 'include',
        ignoreResponseError: true,
      })
      currentUser.value = res?.data?.id ? (res.data as AdminAccount) : null
    } catch {
      currentUser.value = null
    }
    authChecked.value = true
  }

  async function login(username: string, password: string): Promise<LoginResult> {
    try {
      const res = await $fetch<{
        data?: { id: string; name: string; email: string; roles: string[] }
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/login', {
        method: 'POST',
        baseURL: '/',
        body: { username, password },
        credentials: 'include',
        ignoreResponseError: true,
      })

      if (res?.data?.id) {
        const account: AdminAccount = {
          id: String(res.data.id),
          name: res.data.name,
          email: res.data.email,
          roles: res.data.roles,
        }
        currentUser.value = account
        authChecked.value = true
        return { success: true, account }
      }

      const detail = res?.errors?.[0]?.detail || 'Invalid username or password.'
      return { success: false, error: detail }
    } catch {
      return { success: false, error: 'Unable to reach the server. Please try again.' }
    }
  }

  async function register(
    name: string,
    email: string,
    password: string,
    inviteToken?: string,
  ): Promise<LoginResult> {
    try {
      const res = await $fetch<{
        data?: { id: string; name: string; email: string; roles: string[] }
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/register', {
        method: 'POST',
        baseURL: '/',
        body: { name, email, password, ...(inviteToken ? { invite_token: inviteToken } : {}) },
        credentials: 'include',
        ignoreResponseError: true,
      })

      if (res?.data?.id) {
        const account: AdminAccount = {
          id: String(res.data.id),
          name: res.data.name,
          email: res.data.email,
          roles: res.data.roles,
        }
        currentUser.value = account
        authChecked.value = true
        return { success: true, account }
      }

      const detail = res?.errors?.[0]?.detail || 'Registration failed.'
      return { success: false, error: detail }
    } catch {
      return { success: false, error: 'Unable to connect to server' }
    }
  }

  async function forgotPassword(email: string): Promise<{ ok: boolean; message?: string; error?: string }> {
    try {
      const res = await $fetch<{
        message?: string
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/forgot-password', {
        method: 'POST',
        baseURL: '/',
        body: { email },
        credentials: 'include',
        ignoreResponseError: true,
      })

      if (res?.errors?.length) {
        const detail = res.errors[0]?.detail || 'Request failed.'
        return { ok: false, error: detail }
      }

      return { ok: true, message: res?.message }
    } catch {
      return { ok: false, error: 'Unable to connect to server' }
    }
  }

  async function resetPassword(
    token: string,
    password: string,
    passwordConfirmation: string,
  ): Promise<{ ok: boolean; message?: string; error?: string }> {
    try {
      const res = await $fetch<{
        message?: string
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/reset-password', {
        method: 'POST',
        baseURL: '/',
        body: { token, password, password_confirmation: passwordConfirmation },
        credentials: 'include',
        ignoreResponseError: true,
      })

      if (res?.errors?.length) {
        const detail = res.errors[0]?.detail || 'Password reset failed.'
        return { ok: false, error: detail }
      }

      return { ok: true, message: res?.message }
    } catch {
      return { ok: false, error: 'Unable to connect to server' }
    }
  }

  async function verifyEmail(token: string): Promise<{ ok: boolean; error?: string }> {
    try {
      const res = await $fetch<{
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/verify-email', {
        method: 'POST',
        baseURL: '/',
        body: { token },
        credentials: 'include',
        ignoreResponseError: true,
      })

      if (res?.errors?.length) {
        const detail = res.errors[0]?.detail || 'Email verification failed.'
        return { ok: false, error: detail }
      }

      if (currentUser.value) {
        currentUser.value = { ...currentUser.value, emailVerified: true }
      }

      return { ok: true }
    } catch {
      return { ok: false, error: 'Unable to connect to server' }
    }
  }

  async function resendVerification(): Promise<{ ok: boolean; error?: string }> {
    try {
      const res = await $fetch<{
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/resend-verification', {
        method: 'POST',
        baseURL: '/',
        credentials: 'include',
        ignoreResponseError: true,
      })

      if (res?.errors?.length) {
        const detail = res.errors[0]?.detail || 'Failed to resend verification email.'
        return { ok: false, error: detail }
      }

      return { ok: true }
    } catch {
      return { ok: false, error: 'Unable to connect to server' }
    }
  }

  async function logout(): Promise<void> {
    try {
      await $fetch('/api/auth/logout', {
        method: 'POST',
        baseURL: '/',
        credentials: 'include',
        ignoreResponseError: true,
      })
    } catch {
      // Best-effort — clear local state regardless
    }
    currentUser.value = null
    authChecked.value = false
  }

  return {
    currentUser,
    isAuthenticated,
    checkAuth,
    login,
    register,
    forgotPassword,
    resetPassword,
    verifyEmail,
    resendVerification,
    logout,
  }
}

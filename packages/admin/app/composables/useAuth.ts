import type { AdminAccount } from '../contracts/auth'

export type { AdminAccount }

export interface LoginResult {
  success: boolean
  error?: string
  account?: AdminAccount
  verificationRequired?: boolean
}

const STATE_KEY = 'waaseyaa.auth.user'
const CHECKED_KEY = 'waaseyaa.auth.checked'
const PENDING_VERIFICATION_EMAIL_KEY = 'waaseyaa.auth.pendingVerificationEmail'

export function useAuth() {
  const { apiFetch } = useApi()
  const currentUser = useState<AdminAccount | null>(STATE_KEY, () => null)
  const authChecked = useState<boolean>(CHECKED_KEY, () => false)
  const pendingVerificationEmail = useState<string>(PENDING_VERIFICATION_EMAIL_KEY, () => '')
  const isAuthenticated = computed(() => currentUser.value !== null)

  async function checkAuth(): Promise<void> {
    if (authChecked.value) return
    try {
      const res = await apiFetch<{ data?: AdminAccount }>('/api/user/me', {
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
      const res = await apiFetch<{
        data?: { id: string; name: string; email: string; roles: string[]; emailVerified: boolean }
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/login', {
        method: 'POST',
        body: { username, password },
        ignoreResponseError: true,
      })

      if (res?.data?.id) {
        const account: AdminAccount = {
          id: String(res.data.id),
          name: res.data.name,
          email: res.data.email,
          roles: res.data.roles,
          emailVerified: res.data.emailVerified,
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
      const res = await apiFetch<{
        data?: { id: string; name: string; email: string; roles: string[]; email_verified: boolean }
        meta?: { verification_required?: boolean }
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/register', {
        method: 'POST',
        body: { name, email, password, ...(inviteToken ? { invite_token: inviteToken } : {}) },
        ignoreResponseError: true,
      })

      if (res?.data?.id) {
        const account: AdminAccount = {
          id: String(res.data.id),
          name: res.data.name,
          email: res.data.email,
          roles: res.data.roles,
          emailVerified: res.data.email_verified,
        }
        const verificationRequired = res.meta?.verification_required === true
        if (verificationRequired) {
          currentUser.value = null
          authChecked.value = false
          pendingVerificationEmail.value = res.data.email
        } else {
          currentUser.value = account
          authChecked.value = true
          pendingVerificationEmail.value = ''
        }
        return { success: true, account, verificationRequired }
      }

      const detail = res?.errors?.[0]?.detail || 'Registration failed.'
      return { success: false, error: detail }
    } catch {
      return { success: false, error: 'Unable to connect to server' }
    }
  }

  async function forgotPassword(email: string): Promise<{ ok: boolean; message?: string; error?: string }> {
    try {
      const res = await apiFetch<{
        message?: string
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/forgot-password', {
        method: 'POST',
        body: { email },
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
      const res = await apiFetch<{
        message?: string
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/reset-password', {
        method: 'POST',
        body: { token, password, password_confirmation: passwordConfirmation },
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

  async function verifyEmail(token: string): Promise<{ ok: boolean; error?: string; redirect?: string }> {
    try {
      const res = await apiFetch<{
        error?: string
        errors?: Array<{ status: string; title: string; detail?: string }>
        meta?: { redirect?: string }
      }>('/api/auth/verify-email', {
        method: 'POST',
        body: { token },
        ignoreResponseError: true,
      })

      if (res?.error || res?.errors?.length) {
        const detail = res.errors?.[0]?.detail || 'Email verification failed.'
        return { ok: false, error: detail }
      }

      if (currentUser.value) {
        currentUser.value = { ...currentUser.value, emailVerified: true }
      }
      pendingVerificationEmail.value = ''

      return { ok: true, redirect: res?.meta?.redirect }
    } catch {
      return { ok: false, error: 'Unable to connect to server' }
    }
  }

  async function resendVerification(email?: string): Promise<{ ok: boolean; error?: string }> {
    const address = email?.trim()
      || currentUser.value?.email?.trim()
      || pendingVerificationEmail.value.trim()
      || ''
    if (!address) {
      return { ok: false, error: 'Enter the email address used for registration.' }
    }

    try {
      const res = await apiFetch<{
        error?: string
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/resend-verification', {
        method: 'POST',
        body: { email: address },
        ignoreResponseError: true,
      })

      if (res?.error || res?.errors?.length) {
        const detail = res.errors?.[0]?.detail || 'Failed to resend verification email.'
        return { ok: false, error: detail }
      }

      return { ok: true }
    } catch {
      return { ok: false, error: 'Unable to connect to server' }
    }
  }

  async function logout(): Promise<void> {
    try {
      await apiFetch('/api/auth/logout', {
        method: 'POST',
        ignoreResponseError: true,
      })
    } catch {
      // Best-effort — clear local state regardless
    }
    currentUser.value = null
    authChecked.value = false
    pendingVerificationEmail.value = ''
  }

  return {
    currentUser,
    pendingVerificationEmail,
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

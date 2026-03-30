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

  async function logout(): Promise<void> {
    try {
      await $fetch('/api/auth/logout', {
        method: 'POST',
        credentials: 'include',
        ignoreResponseError: true,
      })
    } catch {
      // Best-effort — clear local state regardless
    }
    currentUser.value = null
    authChecked.value = false
  }

  return { currentUser, isAuthenticated, checkAuth, login, logout }
}

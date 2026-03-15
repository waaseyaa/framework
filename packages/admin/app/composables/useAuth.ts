import type { AdminRuntime } from '../contracts/runtime'
import type { AdminAccount } from '../contracts/auth'

export type { AdminAccount }

const STATE_KEY = 'waaseyaa.auth.user'
const CHECKED_KEY = 'waaseyaa.auth.checked'

export function useAuth() {
  const currentUser = useState<AdminAccount | null>(STATE_KEY, () => null)
  const authChecked = useState<boolean>(CHECKED_KEY, () => false)
  const isAuthenticated = computed(() => currentUser.value !== null)

  function getRuntime(): AdminRuntime {
    const { $admin } = useNuxtApp() as unknown as { $admin: AdminRuntime }
    return $admin
  }

  async function checkAuth(): Promise<void> {
    if (authChecked.value) return
    const runtime = getRuntime()
    const session = await runtime.auth.getSession()
    currentUser.value = session?.account ?? null
    authChecked.value = true
  }

  async function login(username: string, password: string): Promise<void> {
    const runtime = getRuntime()
    const strategy = runtime.bootstrap.auth.strategy

    if (strategy === 'redirect') {
      const returnTo = window.location.pathname
      window.location.href = runtime.auth.getLoginUrl(returnTo)
      return
    }

    // Embedded strategy — POST to loginEndpoint
    const endpoint = runtime.bootstrap.auth.loginEndpoint
    if (!endpoint) throw new Error('No loginEndpoint configured for embedded auth strategy')

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    })
    if (!response.ok) throw new Error('Login failed')

    const session = await runtime.auth.getSession()
    currentUser.value = session?.account ?? null
  }

  async function logout(): Promise<void> {
    const runtime = getRuntime()
    await runtime.auth.logout()
    currentUser.value = null
    authChecked.value = false
  }

  return { currentUser, isAuthenticated, checkAuth, login, logout }
}

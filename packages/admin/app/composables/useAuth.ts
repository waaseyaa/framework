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
    const returnTo = window.location.pathname
    window.location.href = runtime.auth.getLoginUrl(returnTo)
  }

  async function logout(): Promise<void> {
    const runtime = getRuntime()
    await runtime.auth.logout()
    currentUser.value = null
    authChecked.value = false
  }

  return { currentUser, isAuthenticated, checkAuth, login, logout }
}

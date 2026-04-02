import type { AdminRuntime } from '~/contracts'
import { isPublicAuthPath } from '~/runtime/publicAuthPaths'

export default defineNuxtRouteMiddleware(async (to) => {
  // Auth check runs client-side only. The PHP backend is the authoritative
  // security layer; the Nuxt middleware is a UX redirect guard.
  if (!import.meta.client) return

  const config = useRuntimeConfig()
  const baseUrl = (config.public.baseUrl as string) || ''
  if (isPublicAuthPath(to.path, baseUrl)) return

  // Embedded auth strategy guard — check session via adapter
  const nuxtApp = useNuxtApp()
  const admin = (nuxtApp as unknown as { $admin: AdminRuntime | null }).$admin
  if (!admin) {
    return navigateTo('/login')
  }

  const strategy = admin.authConfig?.strategy
  if (strategy === 'embedded') {
    const { isAuthenticated, checkAuth } = useAuth()
    await checkAuth()
    if (!isAuthenticated.value) {
      return navigateTo('/login')
    }
  }

  // ensureVerifiedEmail: when requireVerifiedEmail is enabled in auth config,
  // redirect unverified users to /verify-email before accessing any protected page.
  const authConfig = config.public.auth as Record<string, unknown> | undefined
  const requireVerified = authConfig?.requireVerifiedEmail

  if (requireVerified) {
    const { currentUser } = useAuth()
    if (currentUser.value && !currentUser.value.emailVerified) {
      return navigateTo('/verify-email')
    }
  }
})

import type { AdminRuntime } from '~/contracts'

export default defineNuxtRouteMiddleware(async (to) => {
  // Auth check runs client-side only. The PHP backend is the authoritative
  // security layer; the Nuxt middleware is a UX redirect guard.
  if (!import.meta.client) {
    return
  }

  if (to.path === '/login') {
    return
  }

  // Check if $admin is available — if not, plugin may have redirected already
  const nuxtApp = useNuxtApp()
  const admin = (nuxtApp as unknown as { $admin: AdminRuntime | null }).$admin
  if (!admin) {
    return navigateTo('/login')
  }

  // For embedded auth strategy, check session via adapter
  const strategy = admin.bootstrap?.auth?.strategy
  if (strategy === 'embedded') {
    const { isAuthenticated, checkAuth } = useAuth()
    await checkAuth()
    if (!isAuthenticated.value) {
      return navigateTo('/login')
    }
  }

  // For redirect strategy, the plugin handles auth — if we got here, bootstrap succeeded
  // which means the user is authenticated.
})

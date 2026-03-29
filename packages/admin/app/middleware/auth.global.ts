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

  // If we got here, the surface session succeeded — user is authenticated.
})

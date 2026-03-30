export default defineNuxtRouteMiddleware(async (to) => {
  // Auth is handled entirely by the admin plugin. If the AdminSurface session
  // returns 401, the plugin redirects to the PHP backend's login page via
  // window.location.href (full page navigation, not SPA routing).
  //
  // ensureVerifiedEmail: when requireVerifiedEmail is enabled in auth config,
  // redirect unverified users to /verify-email before accessing any protected page.
  if (!import.meta.client) return

  const publicPaths = ['/login', '/register', '/forgot-password', '/reset-password', '/verify-email']
  if (publicPaths.includes(to.path)) return

  const config = useRuntimeConfig()
  const authConfig = config.public.auth as Record<string, unknown> | undefined
  const requireVerified = authConfig?.requireVerifiedEmail

  if (!requireVerified) return

  const { currentUser } = useAuth()
  if (currentUser.value && !currentUser.value.emailVerified) {
    return navigateTo('/verify-email')
  }
})

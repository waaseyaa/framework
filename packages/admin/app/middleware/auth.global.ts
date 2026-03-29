export default defineNuxtRouteMiddleware(async () => {
  // Auth is handled entirely by the admin plugin. If the AdminSurface session
  // returns 401, the plugin redirects to the PHP backend's login page via
  // window.location.href (full page navigation, not SPA routing).
  //
  // This middleware is intentionally empty — it exists as a named extension
  // point for host apps that need additional route guards.
})

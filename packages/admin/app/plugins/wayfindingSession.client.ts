/**
 * Surface the caller's own Wayfinding session token as `data-wf-session` on the
 * document root (Phase 5 hardening, P0-2).
 *
 * This is the in-page half of the supported read path: a presenter (or any page
 * tooling) can read `document.documentElement.dataset.wfSession` to learn the
 * token that addresses THIS viewer's session for a live trail — no SSE
 * interception, no hydration race. The value comes from the race-free endpoint
 * GET /api/wayfinding/session (root-level framework API — NOT under the admin
 * SPA baseURL) and is identical to the SSE `connected` frame's token.
 * Best-effort: unauthenticated/login pages return no token and the attribute is
 * simply not set. Runs after the admin auth bootstrap plugin (alphabetical order
 * puts `admin.ts` first), so the session cookie is established by the time this
 * fires; fire-and-forget so it never blocks app boot.
 */
export default defineNuxtPlugin(() => {
  if (!import.meta.client) {
    return
  }

  void (async () => {
    try {
      // Native fetch, NOT Nuxt's $fetch: $fetch applies the admin app's default
      // baseURL (/admin/), which would rewrite this root-level framework path to
      // /admin/api/wayfinding/session (caught by the SPA fallback). This mirrors
      // useRealtime's raw `new EventSource('/api/broadcast')`.
      const res = await fetch('/api/wayfinding/session', { credentials: 'include' })
      if (!res.ok) {
        return
      }
      const body = (await res.json()) as { data?: { sessionToken?: string | null } }
      const token = body?.data?.sessionToken
      if (typeof token === 'string' && token !== '') {
        document.documentElement.setAttribute('data-wf-session', token)
      }
    } catch {
      // Optional presenter convenience — never block app boot on it.
    }
  })()
})

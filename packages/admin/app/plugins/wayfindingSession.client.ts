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
 * Unauthenticated pages and installs without Wayfinding make no request at all.
 * Runs after the admin auth bootstrap plugin (alphabetical order puts `admin.ts`
 * first), so both the authenticated account and exact optional-package feature
 * projection are known before activation. Fire-and-forget so it never blocks
 * app boot; auth loss aborts an in-flight request and clears the exposed token.
 */
import type { AdminRuntime } from '~/contracts/runtime'

export default defineNuxtPlugin((nuxtApp) => {
  if (!import.meta.client) {
    return
  }

  const admin = (nuxtApp as unknown as { $admin: AdminRuntime | null }).$admin
  const currentUser = useState<AdminRuntime['account'] | null>('waaseyaa.auth.user', () => null)
  const clearToken = () => document.documentElement.removeAttribute('data-wf-session')

  clearToken()
  if (admin?.features?.wayfinding !== true || currentUser.value === null) {
    return
  }

  const controller = new AbortController()
  const stopAuthWatch = watch(currentUser, (account) => {
    if (account === null) {
      controller.abort()
      clearToken()
    }
  }, { flush: 'sync' })
  nuxtApp.vueApp.onUnmount(() => {
    stopAuthWatch()
    controller.abort()
    clearToken()
  })

  void (async () => {
    try {
      // Native fetch, NOT Nuxt's $fetch: $fetch applies the admin app's default
      // baseURL (/admin/), which would rewrite this root-level framework path to
      // /admin/api/wayfinding/session (caught by the SPA fallback). This mirrors
      // useRealtime's raw `new EventSource('/api/broadcast')`.
      const res = await fetch('/api/wayfinding/session', {
        credentials: 'include',
        signal: controller.signal,
      })
      if (!res.ok) {
        return
      }
      const body = (await res.json()) as { data?: { sessionToken?: string | null } }
      const token = body?.data?.sessionToken
      if (!controller.signal.aborted && currentUser.value !== null && typeof token === 'string' && token !== '') {
        document.documentElement.setAttribute('data-wf-session', token)
      }
    } catch {
      // Optional presenter convenience — never block app boot on it. Aborting
      // on auth loss follows this path too; the token has already been cleared.
    }
  })()
})

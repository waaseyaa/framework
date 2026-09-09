import { readDocumentCsrfCookieToken, resolveCsrfCookieName } from '~/utils/csrfCookie'

const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS']

function isSameOrigin(target: string): boolean {
  // Relative paths always resolve against the current origin.
  if (!/^[a-z][a-z0-9+.-]*:/i.test(target) && !target.startsWith('//')) return true
  if (typeof window === 'undefined') return false
  try {
    return new URL(target, window.location.origin).origin === window.location.origin
  } catch {
    return false
  }
}

function configuredCsrfCookieName(): string {
  try {
    const pub = useRuntimeConfig().public as { csrfCookieName?: string }
    return resolveCsrfCookieName(pub.csrfCookieName)
  }
  catch {
    return resolveCsrfCookieName()
  }
}

export function useApi() {
  async function apiFetch<T>(path: string, options: Record<string, unknown> = {}): Promise<T> {
    const method = String(options.method ?? 'GET').toUpperCase()
    const headers = { ...((options.headers as Record<string, string> | undefined) ?? {}) }

    // CSRF double-submit: forward the configured CSRF cookie as a header on
    // state-changing requests only, and never to cross-origin destinations.
    if (!SAFE_METHODS.includes(method) && isSameOrigin(path)) {
      const token = readDocumentCsrfCookieToken(configuredCsrfCookieName())
      if (token && !headers['X-XSRF-TOKEN']) headers['X-XSRF-TOKEN'] = token
    }

    return $fetch<T>(path, {
      // JSON API routes are rooted at /api, independently of the Nuxt app's
      // mount path. Using app.baseURL here rewrites /api to /admin/api when
      // the SPA is mounted at /admin and lets the HTML catch-all swallow it.
      baseURL: '/',
      credentials: 'include',
      ...options,
      ...(Object.keys(headers).length > 0 ? { headers } : {}),
    } as any)
  }

  return { apiFetch }
}

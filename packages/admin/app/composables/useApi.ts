export function useApi() {
  async function apiFetch<T>(path: string, options: Record<string, unknown> = {}): Promise<T> {
    return $fetch<T>(path, {
      // JSON API routes are rooted at /api, independently of the Nuxt app's
      // mount path. Using app.baseURL here rewrites /api to /admin/api when
      // the SPA is mounted at /admin and lets the HTML catch-all swallow it.
      baseURL: '/',
      credentials: 'include',
      ...options,
    } as any)
  }

  return { apiFetch }
}

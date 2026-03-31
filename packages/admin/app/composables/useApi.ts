export function useApi() {
  async function apiFetch<T>(path: string, options: Record<string, unknown> = {}): Promise<T> {
    return $fetch<T>(path, {
      baseURL: '/',
      credentials: 'include',
      ...options,
    } as any)
  }

  return { apiFetch }
}

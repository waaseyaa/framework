import type { FetchOptions } from 'ofetch'

export function useApi() {
  async function apiFetch<T>(path: string, options: FetchOptions = {}): Promise<T> {
    return $fetch<T>(path, {
      baseURL: '/',
      credentials: 'include',
      ...options,
    })
  }

  return { apiFetch }
}

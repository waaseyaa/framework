import { describe, it, expect, vi } from 'vitest'
import { useApi } from '../../../app/composables/useApi'

const mockFetch = vi.fn().mockResolvedValue({ data: 'test' })
vi.stubGlobal('$fetch', mockFetch)

describe('useApi', () => {
  it('uses the canonical root API base when the SPA is mounted under /admin', async () => {
    vi.stubGlobal('useRuntimeConfig', () => ({ app: { baseURL: '/admin/' } }))
    const { apiFetch } = useApi()
    await apiFetch('/api/user/me')
    expect(mockFetch).toHaveBeenCalledWith('/api/user/me', {
      baseURL: '/',
      credentials: 'include',
    })
  })

  it('merges caller options', async () => {
    vi.stubGlobal('useRuntimeConfig', () => ({ app: { baseURL: '/admin/' } }))
    const { apiFetch } = useApi()
    await apiFetch('/api/auth/login', { method: 'POST', body: { user: 'a' } })
    expect(mockFetch).toHaveBeenCalledWith('/api/auth/login', {
      baseURL: '/',
      credentials: 'include',
      method: 'POST',
      body: { user: 'a' },
    })
  })
})

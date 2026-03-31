import { describe, it, expect, vi } from 'vitest'
import { useApi } from '../../../app/composables/useApi'

const mockFetch = vi.fn().mockResolvedValue({ data: 'test' })
vi.stubGlobal('$fetch', mockFetch)

describe('useApi', () => {
  it('passes baseURL and credentials by default', async () => {
    const { apiFetch } = useApi()
    await apiFetch('/api/user/me')
    expect(mockFetch).toHaveBeenCalledWith('/api/user/me', {
      baseURL: '/',
      credentials: 'include',
    })
  })

  it('merges caller options', async () => {
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

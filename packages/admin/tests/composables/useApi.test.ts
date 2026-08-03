import { describe, it, expect, vi, beforeEach } from 'vitest'

function setXsrfCookie(value: string) {
  document.cookie = `XSRF-TOKEN=${value}; path=/`
}

function clearXsrfCookie() {
  document.cookie = 'XSRF-TOKEN=; path=/; max-age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT'
}

function fetchMock() {
  const mock = vi.fn().mockResolvedValue({ data: {} })
  vi.stubGlobal('$fetch', mock)
  return mock
}

function headersOfLastCall(mock: ReturnType<typeof vi.fn>): Record<string, string> {
  const opts = mock.mock.calls[0]?.[1] ?? {}
  return (opts.headers ?? {}) as Record<string, string>
}

describe('useApi', () => {
  beforeEach(() => {
    clearXsrfCookie()
    vi.unstubAllGlobals()
  })

  it('sends X-XSRF-TOKEN on POST when the cookie is present, URL-decoded', async () => {
    const token = 'abc123def456'
    setXsrfCookie(encodeURIComponent(token))
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch('/api/mcp/approvals', { method: 'POST', body: { decision: 'approve' } })

    expect(headersOfLastCall(mock)['X-XSRF-TOKEN']).toBe(token)
  })

  it('URL-decodes percent-encoded cookie values before sending', async () => {
    // Simulates a token that the server rawurlencode()d into the cookie.
    setXsrfCookie('token%2Bwith%2Fchars')
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch('/api/thing', { method: 'DELETE' })

    expect(headersOfLastCall(mock)['X-XSRF-TOKEN']).toBe('token+with/chars')
  })

  it('does not send X-XSRF-TOKEN on GET requests', async () => {
    setXsrfCookie('sometoken')
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch('/api/user/me')

    expect(headersOfLastCall(mock)['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('does not send X-XSRF-TOKEN when the cookie is absent', async () => {
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch('/api/thing', { method: 'POST', body: {} })

    expect(headersOfLastCall(mock)['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('does not leak X-XSRF-TOKEN to cross-origin absolute URLs', async () => {
    setXsrfCookie('sometoken')
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch('https://evil.example/api/thing', { method: 'POST', body: {} })

    expect(headersOfLastCall(mock)['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('does not leak X-XSRF-TOKEN to protocol-relative cross-origin URLs', async () => {
    setXsrfCookie('sometoken')
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch('//evil.example/api/thing', { method: 'POST', body: {} })

    expect(headersOfLastCall(mock)['X-XSRF-TOKEN']).toBeUndefined()
  })

  it('sends X-XSRF-TOKEN to same-origin absolute URLs', async () => {
    setXsrfCookie('sometoken')
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch(`${window.location.origin}/api/thing`, { method: 'POST', body: {} })

    expect(headersOfLastCall(mock)['X-XSRF-TOKEN']).toBe('sometoken')
  })

  it('preserves caller-supplied headers alongside the token header', async () => {
    setXsrfCookie('sometoken')
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch('/api/thing', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/vnd.api+json' },
    })

    const headers = headersOfLastCall(mock)
    expect(headers['Content-Type']).toBe('application/vnd.api+json')
    expect(headers['X-XSRF-TOKEN']).toBe('sometoken')
  })

  it('keeps credentials and baseURL defaults intact', async () => {
    const mock = fetchMock()

    const { useApi } = await import('~/composables/useApi')
    await useApi().apiFetch('/api/user/me')

    const opts = mock.mock.calls[0]?.[1] ?? {}
    expect(opts.baseURL).toBe('/')
    expect(opts.credentials).toBe('include')
  })
})

import { describe, expect, it } from 'vitest'
import { isPublicAuthPath, normalizeAdminPath, PUBLIC_AUTH_PATHS } from '~/runtime/publicAuthPaths'

describe('public auth path runtime helpers', () => {
  it('normalizes trailing slashes to canonical auth paths', () => {
    expect(normalizeAdminPath('/login/')).toBe('/login')
    expect(normalizeAdminPath('/verify-email///')).toBe('/verify-email')
  })

  it('normalizes admin-subpath auth routes to the same canonical paths', () => {
    expect(normalizeAdminPath('/admin/login', '/admin')).toBe('/login')
    expect(normalizeAdminPath('/admin/reset-password/', '/admin')).toBe('/reset-password')
  })

  it('recognizes the governed public auth paths after normalization', () => {
    expect(PUBLIC_AUTH_PATHS).toEqual(['/login', '/register', '/forgot-password', '/reset-password', '/verify-email'])
    expect(isPublicAuthPath('/login')).toBe(true)
    expect(isPublicAuthPath('/admin/login/', '/admin')).toBe(true)
    expect(isPublicAuthPath('/admin/verify-email///', '/admin')).toBe(true)
    expect(isPublicAuthPath('/admin/node', '/admin')).toBe(false)
  })
})

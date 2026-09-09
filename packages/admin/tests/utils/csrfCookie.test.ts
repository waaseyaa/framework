import { describe, expect, it } from 'vitest'
import {
  DEFAULT_CSRF_COOKIE_NAME,
  readCsrfCookieToken,
  resolveCsrfCookieName,
} from '../../app/utils/csrfCookie'

describe('csrfCookie shared decoder (#3031/#3047)', () => {
  it('returns null when the cookie is absent', () => {
    expect(readCsrfCookieToken('other=1', DEFAULT_CSRF_COOKIE_NAME)).toBeNull()
  })

  it('URL-decodes the default XSRF-TOKEN cookie', () => {
    const encoded = encodeURIComponent('token+with/chars')
    expect(readCsrfCookieToken(`XSRF-TOKEN=${encoded}`, DEFAULT_CSRF_COOKIE_NAME))
      .toBe('token+with/chars')
  })

  it('reads a configured host-bound cookie name', () => {
    const encoded = encodeURIComponent('host-token')
    expect(readCsrfCookieToken(
      `__Host-XSRF-TOKEN=${encoded}; path=/`,
      '__Host-XSRF-TOKEN',
    )).toBe('host-token')
  })

  it('ignores sibling cookies with a similar prefix', () => {
    expect(readCsrfCookieToken(
      'XSRF-TOKEN-EXTRA=nope; XSRF-TOKEN=yes',
      DEFAULT_CSRF_COOKIE_NAME,
    )).toBe('yes')
  })

  it('resolveCsrfCookieName prefers an explicit configured name', () => {
    expect(resolveCsrfCookieName('__Host-XSRF-TOKEN')).toBe('__Host-XSRF-TOKEN')
    expect(resolveCsrfCookieName(null)).toBe(DEFAULT_CSRF_COOKIE_NAME)
    expect(resolveCsrfCookieName('')).toBe(DEFAULT_CSRF_COOKIE_NAME)
  })
})

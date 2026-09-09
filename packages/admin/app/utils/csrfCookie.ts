/**
 * Canonical Admin CSRF cookie reader (#3031 / #3047).
 *
 * Both `useApi` and upload transports must read the configured cookie name
 * through this decoder — never a second ad-hoc parser. The cookie value is
 * URL-encoded by `CsrfMiddleware`; callers receive the decoded token suitable
 * for the `X-XSRF-TOKEN` request header.
 */

export const DEFAULT_CSRF_COOKIE_NAME = 'XSRF-TOKEN'

/**
 * Read and URL-decode the CSRF double-submit cookie from a cookie header string.
 */
export function readCsrfCookieToken(
  cookieSource: string,
  cookieName: string = DEFAULT_CSRF_COOKIE_NAME,
): string | null {
  if (cookieSource === '' || cookieName === '') {
    return null
  }

  const prefix = `${cookieName}=`
  for (const segment of cookieSource.split(';')) {
    const trimmed = segment.trim()
    if (!trimmed.startsWith(prefix)) {
      continue
    }

    try {
      return decodeURIComponent(trimmed.slice(prefix.length))
    }
    catch {
      // Malformed escape sequences: surface the raw value rather than throwing
      // mid-request. Server-side hash_equals will refuse a bad token.
      return trimmed.slice(prefix.length)
    }
  }

  return null
}

/**
 * Resolve the configured CSRF cookie name for the Admin SPA.
 *
 * Prefers `runtimeConfig.public.csrfCookieName` when Nuxt context is available;
 * otherwise the framework default (`XSRF-TOKEN`) matching SessionCookiePolicy.
 */
export function resolveCsrfCookieName(
  configuredName?: string | null,
): string {
  if (typeof configuredName === 'string' && configuredName !== '') {
    return configuredName
  }

  return DEFAULT_CSRF_COOKIE_NAME
}

/**
 * Browser helper: read the configured CSRF cookie from `document.cookie`.
 */
export function readDocumentCsrfCookieToken(
  cookieName: string = DEFAULT_CSRF_COOKIE_NAME,
): string | null {
  if (typeof document === 'undefined') {
    return null
  }

  return readCsrfCookieToken(document.cookie, cookieName)
}

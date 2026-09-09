/**
 * Typed envelope for Waaseyaa admin SPA runtime configuration.
 *
 * Consumers must use useAdminConfig() to obtain this object.
 * Do NOT call useRuntimeConfig().public directly in components, pages, or composables.
 *
 * @api
 */
export interface AdminConfig {
  /** Whether realtime SSE features are enabled. */
  readonly enableRealtime: boolean
  /** Application display name. */
  readonly appName: string
  /** URL to the documentation site. Trailing slash removed. */
  readonly docsUrl: string
  /** Base URL of the API. Trailing slash removed. */
  readonly baseUrl: string
  /** Optional logo URL for brand panels. */
  readonly logoUrl: string | undefined
  readonly auth: {
    /** Registration mode: 'open' | 'invite' | 'closed' */
    readonly registration: string
    /** Whether email verification is required before login. */
    readonly requireVerifiedEmail: boolean
  }
  /**
   * CSRF double-submit cookie name. Must match PHP `session.cookie.csrf_name`
   * (default `XSRF-TOKEN`; host-bound `__Host-XSRF-TOKEN`).
   */
  readonly csrfCookieName: string
}

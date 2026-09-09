import type { AdminConfig } from '~/types/AdminConfig'
import { asBoolean, asString, asUrl } from '~/composables/configCoercion'

/**
 * Returns typed, coerced admin runtime configuration.
 *
 * Use this composable instead of useRuntimeConfig().public in all admin SPA
 * components, pages, and composables. The coercion layer normalizes Nuxt's
 * digit-string serialization quirks at a single boundary.
 *
 * Referentially stable within a component tree lifecycle (NFR-002).
 *
 * @api
 */
export function useAdminConfig(): Readonly<AdminConfig> {
  return useState<AdminConfig>('waaseyaa-admin-config', (): AdminConfig => {
    const runtimeConfig = useRuntimeConfig()
    const pub = runtimeConfig.public

    return {
      enableRealtime: asBoolean(pub.enableRealtime),
      appName: asString(pub.appName, 'Waaseyaa Admin'),
      docsUrl: asUrl(pub.docsUrl, ''),
      baseUrl: asUrl(pub.baseUrl, ''),
      logoUrl: pub.logoUrl != null ? asString(pub.logoUrl) : undefined,
      auth: {
        registration: asString(pub.auth?.registration, 'open'),
        requireVerifiedEmail: asBoolean(pub.auth?.requireVerifiedEmail),
      },
      csrfCookieName: asString(pub.csrfCookieName, 'XSRF-TOKEN'),
    }
  }).value
}

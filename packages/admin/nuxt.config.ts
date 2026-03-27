const backendUrl = process.env.NUXT_BACKEND_URL ?? 'http://127.0.0.1:8080'

export default defineNuxtConfig({
  compatibilityDate: '2025-01-01',
  devtools: { enabled: true },
  ssr: false,

  nitro: {
    prerender: {
      // /login is proxied to PHP during generate; backend may be unreachable in CI.
      failOnError: false,
    },
  },

  srcDir: 'app/',

  app: {
    baseURL: '/admin/',
    head: {
      title: 'Waaseyaa',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      ],
    },
  },

  routeRules: {
    '/api/**': { proxy: `${backendUrl}/api/**` },
    '/admin/surface/**': { proxy: `${backendUrl}/admin/surface/**` },
    '/admin/bootstrap': { proxy: `${backendUrl}/admin/bootstrap` },
    '/login': { proxy: `${backendUrl}/login` },
  },

  runtimeConfig: {
    public: {
      // Disable SSE by default in dev to avoid php -S single-process request starvation.
      // Set NUXT_PUBLIC_ENABLE_REALTIME=1 to force-enable.
      enableRealtime: process.env.NUXT_PUBLIC_ENABLE_REALTIME ?? (process.env.NODE_ENV === 'production' ? '1' : '0'),
      // Override site name via NUXT_PUBLIC_APP_NAME env var (e.g. "Minoo").
      appName: process.env.NUXT_PUBLIC_APP_NAME ?? 'Waaseyaa',
      // Quickstart docs link used by onboarding prompt.
      docsUrl: process.env.NUXT_PUBLIC_DOCS_URL ?? 'https://github.com/jonesrussell/waaseyaa',
      // Base URL for subpath mounting (e.g. "/admin"). Used by admin plugin for bootstrap resolution.
      baseUrl: process.env.NUXT_PUBLIC_BASE_URL ?? '',
    },
  },
})

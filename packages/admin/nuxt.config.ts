const backendUrl = process.env.NUXT_BACKEND_URL ?? 'http://127.0.0.1:8080'

export default defineNuxtConfig({
  compatibilityDate: '2025-01-01',
  // bin/build-admin-dist derives this from the complete source signature so
  // unchanged release builds use the same Nuxt manifest identity.
  buildId: process.env.WAASEYAA_ADMIN_BUILD_ID,
  devtools: { enabled: process.env.NODE_ENV !== 'production' },
  ssr: false,

  modules: ['@nuxt/eslint'],

  css: ['~/assets/admin.css'],

  eslint: {
    config: {
      stylistic: false,
    },
  },

  nitro: {
    prerender: {
      // Backend may be unreachable during static generation in CI.
      failOnError: false,
    },
  },

  srcDir: 'app/',

  app: {
    baseURL: '/admin/',
    head: {
      // Parameterized via NUXT_PUBLIC_APP_NAME so the static prerendered title
      // (visible before JS hydrates) matches the runtime brand for downstream
      // apps like Minoo. Same fallback as runtimeConfig.public.appName below.
      title: process.env.NUXT_PUBLIC_APP_NAME ?? 'Waaseyaa',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      ],
    },
  },

  routeRules: {
    '/api/**': { proxy: `${backendUrl}/api/**` },
    '/admin/_surface/**': { proxy: `${backendUrl}/admin/_surface/**` },
  },

  runtimeConfig: {
    backendUrl: process.env.NUXT_BACKEND_URL ?? 'http://127.0.0.1:8080',
    public: {
      // Disable SSE by default in dev to avoid php -S single-process request starvation.
      // Set NUXT_PUBLIC_ENABLE_REALTIME=1 to force-enable.
      enableRealtime: process.env.NUXT_PUBLIC_ENABLE_REALTIME ?? (process.env.NODE_ENV === 'production' ? '1' : '0'),
      // Override site name via NUXT_PUBLIC_APP_NAME env var (e.g. "Minoo").
      appName: process.env.NUXT_PUBLIC_APP_NAME ?? 'Waaseyaa',
      // Quickstart docs link used by onboarding prompt.
      docsUrl: process.env.NUXT_PUBLIC_DOCS_URL ?? 'https://github.com/jonesrussell/waaseyaa',
      // Base URL for subpath mounting (e.g. "/admin"). Used by admin plugin for bootstrap resolution.
      baseUrl: process.env.NUXT_PUBLIC_BASE_URL ?? '/admin',
      auth: {
        registration: process.env.NUXT_PUBLIC_AUTH_REGISTRATION ?? 'admin',
        requireVerifiedEmail: process.env.NUXT_PUBLIC_AUTH_REQUIRE_VERIFIED_EMAIL === '1',
      },
    },
  },

  // Pre-bundle the Vue devtools deps so Vite doesn't discover them at runtime
  // and trigger a mid-request server restart. The restart was tearing down the
  // vite-node IPC socket and producing "Vite Node IPC socket path not
  // configured" errors on the first /admin/ request after dev startup.
  // See https://vite.dev/guide/dep-pre-bundling.html.
  vite: {
    optimizeDeps: {
      include: [
        '@vue/devtools-core',
        '@vue/devtools-kit',
      ],
    },
  },
})

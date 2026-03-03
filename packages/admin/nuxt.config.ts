export default defineNuxtConfig({
  compatibilityDate: '2025-01-01',
  devtools: { enabled: true },

  experimental: {
    viteEnvironmentApi: true,
  },
  srcDir: 'app/',

  nitro: {
    devProxy: {
      '/api': {
        target: 'http://localhost:8081/api',
        changeOrigin: true,
      },
    },
  },

  routeRules: {
    '/api/**': { proxy: 'http://localhost:8081/api/**' },
  },

  app: {
    head: {
      title: 'Waaseyaa',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      ],
    },
  },
})

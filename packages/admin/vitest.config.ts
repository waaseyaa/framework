// packages/admin/vitest.config.ts
import { defineVitestConfig } from '@nuxt/test-utils/config'

export default defineVitestConfig({
  test: {
    environment: 'nuxt',
    setupFiles: ['./tests/setup.ts'],
    restoreMocks: true,
    exclude: ['**/node_modules/**', '**/e2e/**'],
    coverage: {
      provider: 'v8',
      include: ['app/**/*.{ts,vue}'],
      exclude: ['app/**/*.d.ts'],
    },
  },
})

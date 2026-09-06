import { defineConfig, devices } from '@playwright/test'

/**
 * Playwright configuration for the Studio-alpha browser proof (#2789).
 *
 * It runs from `packages/admin`, so the Playwright version and the browser
 * binaries are the ones this repository already pins for `npm run test:e2e` —
 * the harness downloads nothing of its own. It starts no web server: the
 * harness owns backend lifecycle, because the backend under proof is the
 * artifact-installed consumer, not a dev server Playwright could spawn.
 */
export default defineConfig({
  testDir: '.',
  testMatch: 'studio-alpha-admin.spec.ts',
  fullyParallel: false,
  workers: 1,
  forbidOnly: true,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: [['list'], ['json', { outputFile: process.env.STUDIO_ALPHA_PW_REPORT ?? 'studio-alpha-report.json' }]],
  use: {
    baseURL: process.env.STUDIO_ALPHA_BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})

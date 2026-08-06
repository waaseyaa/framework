// packages/admin/playwright.config.ts
import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI
    ? [
        ['line'],
        ['html', { open: 'never' }],
        ['json', { outputFile: 'test-results/results.json' }],
      ]
    : 'html',
  use: {
    baseURL: 'http://localhost:3000/admin',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
  ],
  webServer: {
    // CI uses production-mode `build && preview` because `nuxt dev` exhibits
    // 100x slowdown on GitHub Actions runners (>240s vs <5s local). Local devs
    // keep `npm run dev` for HMR. See #1419 for the dev-mode investigation.
    command: process.env.CI ? 'npm run build && npm run preview' : 'npm run dev',
    url: 'http://localhost:3000/admin',
    reuseExistingServer: !process.env.CI,
    timeout: 240 * 1000,
  },
})

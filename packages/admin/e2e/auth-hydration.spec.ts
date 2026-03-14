// packages/admin/e2e/auth-hydration.spec.ts
import { test, expect } from '@playwright/test'
import { mockUserMeRoute, mockEntityTypesRoute } from './fixtures/routes'

test.describe('Auth hydration', () => {
  test('no hydration warnings on authenticated page load', async ({ page }) => {
    const warnings: string[] = []
    page.on('console', msg => {
      if (msg.type() === 'warning') warnings.push(msg.text())
    })

    await mockUserMeRoute(page)
    await mockEntityTypesRoute(page)
    await page.goto('/')
    await page.waitForLoadState('networkidle')

    const hydrationWarnings = warnings.filter(w => w.includes('Hydration'))
    expect(hydrationWarnings).toHaveLength(0)
  })
})

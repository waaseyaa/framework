// packages/admin/e2e/error-page.spec.ts
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes } from './fixtures/routes'

test.describe('Error page', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
  })

  test('shows branded 404 message on unknown route', async ({ page }) => {
    // Use a multi-segment path that doesn't match any dynamic route pattern
    await page.goto('/no/such/deep/route')
    await expect(
      page.getByText("The page you're looking for doesn't exist."),
    ).toBeVisible()
  })

  test('shows "Back to dashboard" link', async ({ page }) => {
    // Use a 3+ segment path that doesn't match any dynamic route pattern
    // (single-segment paths match [entityType] catch-all and show entity error state instead)
    await page.goto('/no/such/deep/route')
    await expect(page.getByRole('link', { name: 'Back to dashboard' })).toBeVisible()
  })

  test('does not expose raw stack trace', async ({ page }) => {
    await page.goto('/this-route-does-not-exist-404')
    // No <pre> element containing stack trace details should be visible
    await expect(page.locator('pre')).not.toBeVisible()
  })
})

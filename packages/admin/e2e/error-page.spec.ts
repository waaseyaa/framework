// packages/admin/e2e/error-page.spec.ts
import { test, expect } from '@playwright/test'
import { mockUserMeRoute } from './fixtures/routes'

test.describe('Error page', () => {
  test.beforeEach(async ({ page }) => {
    await mockUserMeRoute(page)
  })

  test('shows branded 404 message on unknown route', async ({ page }) => {
    await page.goto('/this-route-does-not-exist-404')
    await expect(
      page.getByText("The page you're looking for doesn't exist."),
    ).toBeVisible()
  })

  test('shows "Back to dashboard" link', async ({ page }) => {
    await page.goto('/this-route-does-not-exist-404')
    await expect(page.getByRole('link', { name: 'Back to dashboard' })).toBeVisible()
  })

  test('does not expose raw stack trace', async ({ page }) => {
    await page.goto('/this-route-does-not-exist-404')
    // No <pre> element containing stack trace details should be visible
    await expect(page.locator('pre')).not.toBeVisible()
  })
})

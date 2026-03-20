// packages/admin/e2e/navigation.spec.ts
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

test.describe('Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
    await page.goto('/')
  })

  test('renders the Dashboard link', async ({ page }) => {
    await expect(page.locator('nav').getByText('Dashboard')).toBeVisible()
  })

  test('renders grouped nav section headings', async ({ page }) => {
    // At least one section heading should appear (e.g., "People")
    const sections = page.locator('.nav-section')
    await expect(sections.first()).toBeVisible()
  })

  test('renders entity type labels in the nav', async ({ page }) => {
    await expect(page.locator('nav').getByText('User')).toBeVisible()
  })
})

// packages/admin/e2e/dashboard.spec.ts
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

test.describe('Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
  })

  test('renders entity type cards with labels', async ({ page }) => {
    await page.goto('./')
    // Use heading role to target card <h2> titles, avoiding duplicate
    // matches with the sidebar navigation which also renders entity labels.
    await expect(page.getByRole('heading', { name: 'User' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Content', exact: true })).toBeVisible()
  })

  test('each card links to the entity type route', async ({ page }) => {
    await page.goto('./', { waitUntil: 'networkidle' })
    await expect(page.getByRole('heading', { name: 'User' })).toBeVisible()
    // Scope to main to avoid matching the sidebar "User" link.
    const userCard = page.locator('main').getByRole('link', { name: 'User' })
    await expect(userCard).toBeVisible({ timeout: 10_000 })
    await expect(userCard).toHaveAttribute('href', /\/user$/)
  })

  test('clicking a card navigates to the entity type list', async ({ page }) => {
    // Mock the entity list API for the user type
    await page.route('**/api/user', (route) =>
      route.fulfill({
        json: { jsonapi: { version: '1.0' }, data: [], meta: { total: 0 }, links: {} },
      }),
    )
    await page.goto('./', { waitUntil: 'networkidle' })
    // Scope to main to avoid matching the sidebar "User" link.
    const userLink = page.locator('main').getByRole('link', { name: 'User' })
    await expect(userLink).toBeVisible({ timeout: 10_000 })
    await userLink.click()
    await expect(page).toHaveURL(/\/user$/)
  })

  test('shows onboarding prompt when no custom types exist', async ({ page }) => {
    // Surface transport: GET /_surface/node_type
    await page.route('**/_surface/node_type**', (route) =>
      route.fulfill({
        json: { ok: true, data: { entities: [], total: 0, offset: 0, limit: 25 } },
      }),
    )
    // Legacy JSON API fallback
    await page.route('**/api/node_type**', (route) =>
      route.fulfill({
        json: { jsonapi: { version: '1.0' }, data: [], meta: { total: 0 }, links: {} },
      }),
    )
    await page.goto('./')
    await expect(page.getByText('Get started with your first content type')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Use Note (built-in)' })).toBeVisible()
  })
})

import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'
import {
  mockUnauthenticatedSession,
  clearUnauthenticatedSession,
  mockLoginSuccess,
} from './fixtures/auth'

test.describe('Login page', () => {
  test('unauthenticated user is redirected to /login', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await page.goto('/')
    await expect(page).toHaveURL(/\/login/)
  })

  test('login page renders Split Panel with app name', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await page.goto('/login')
    await expect(page.locator('.auth-brand-title')).toBeVisible()
    await expect(page.locator('#login-username')).toBeVisible()
    await expect(page.locator('#login-password')).toBeVisible()
  })

  test('successful login redirects to dashboard', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await mockLoginSuccess(page)
    await page.goto('/login')
    await page.fill('#login-username', 'dev-admin')
    await page.fill('#login-password', 'password')

    // After login, the app does a full page reload (reloadNuxtApp).
    // Remove the 401 session mock and register valid session routes
    // so the admin plugin bootstraps successfully on reload.
    await clearUnauthenticatedSession(page)
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)

    await page.click('button[type="submit"]')
    await expect(page).toHaveURL(/\/$/)
  })

  test('failed login shows error message', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await mockLoginSuccess(page, 'dev-admin', 'correct-password')
    await page.goto('/login')
    await page.fill('#login-username', 'dev-admin')
    await page.fill('#login-password', 'wrong-password')
    await page.click('button[type="submit"]')
    await expect(page.locator('[role="alert"]')).toContainText('Invalid credentials')
  })

  test('login with returnTo redirects to original page', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await mockLoginSuccess(page)
    await page.goto('/login?returnTo=/user')
    await page.fill('#login-username', 'dev-admin')
    await page.fill('#login-password', 'password')

    await clearUnauthenticatedSession(page)
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
    // Mock the user entity list for the redirect target
    await page.route('**/api/user', (route) =>
      route.fulfill({
        json: { jsonapi: { version: '1.0' }, data: [], meta: { total: 0 }, links: {} },
      }),
    )

    await page.click('button[type="submit"]')
    await expect(page).toHaveURL(/\/user/)
  })

  test('mobile viewport stacks panels vertically', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 })
    await mockUnauthenticatedSession(page)
    await page.goto('/login')
    const brand = page.locator('.auth-brand')
    const form = page.locator('.auth-form-panel')
    await expect(brand).toBeVisible()
    await expect(form).toBeVisible()
    const brandBox = await brand.boundingBox()
    const formBox = await form.boundingBox()
    expect(brandBox!.y).toBeLessThan(formBox!.y)
  })
})

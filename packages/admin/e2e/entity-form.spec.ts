// packages/admin/e2e/entity-form.spec.ts
import { test, expect } from '@playwright/test'
import {
  mockEntityTypesRoute,
  mockSchemaRoute,
  mockEntityCreateRoute,
} from './fixtures/routes'

test.describe('Entity create form', () => {
  test.beforeEach(async ({ page }) => {
    await mockEntityTypesRoute(page)
    await mockSchemaRoute(page, 'user')
    await mockEntityCreateRoute(page, 'user')
  })

  test('renders form fields from schema', async ({ page }) => {
    await page.goto('/user/create')
    // Username field should render (name property from userSchema fixture)
    await expect(page.getByLabel('Username')).toBeVisible()
  })

  test('shows disabled field for x-access-restricted properties', async ({ page }) => {
    await page.goto('/user/create')
    // Email is x-access-restricted in userSchema fixture
    const emailInput = page.getByLabel('Email')
    await expect(emailInput).toBeDisabled()
  })

  test('submits form and handles success', async ({ page }) => {
    await page.goto('/user/create')
    await page.getByLabel('Username').fill('testuser')
    await page.getByRole('button', { name: /create/i }).click()
    // After successful POST, expect navigation away from create page
    await expect(page).not.toHaveURL('/user/create')
  })
})

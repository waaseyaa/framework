// packages/admin/e2e/workflow-guards.spec.ts
// Smoke test for the workflow guards section on /workflows/{id} (M4A-5 Phase 1, #1470).
// Requires `nuxt dev` on port 3000.
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

const editorialDefinition = {
  id: 'editorial',
  label: 'Editorial',
  states: [
    { id: 'draft', label: 'Draft', weight: 0, metadata: {} },
    { id: 'review', label: 'Review', weight: 10, metadata: {} },
    { id: 'published', label: 'Published', weight: 20, metadata: {} },
  ],
  transitions: [
    { id: 'submit_for_review', label: 'Submit for review', from: ['draft'], to: 'review', weight: 0 },
    { id: 'publish', label: 'Publish', from: ['review'], to: 'published', weight: 10 },
    { id: 'archive', label: 'Archive', from: ['published'], to: 'draft', weight: 20 },
  ],
}

const guardsRows = [
  { bundle: 'article', transition: 'archive', required_roles: ['administrator'] },
  { bundle: 'article', transition: 'publish', required_roles: ['editor', 'administrator'] },
  { bundle: 'article', transition: 'submit_for_review', required_roles: ['contributor', 'reviewer', 'editor', 'administrator'] },
]

test.describe('Workflow guards section on /workflows/{id}', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)

    await page.route('**/api/workflow-definitions', (route) =>
      route.fulfill({ json: { data: [editorialDefinition] } }),
    )
  })

  test('renders the guards matrix with bundle, transition, and required-role chips', async ({ page }) => {
    await page.route('**/api/workflow-definitions/editorial/guards', (route) =>
      route.fulfill({ json: { data: guardsRows } }),
    )

    await page.goto('/workflows/editorial')

    await expect(page.getByTestId('workflow-guards-matrix')).toBeVisible()
    await expect(page.getByTestId('workflow-guards-table')).toBeVisible()

    const rows = page.getByTestId('workflow-guards-table').locator('tbody tr')
    await expect(rows).toHaveCount(3)

    // Row 0: archive — single role chip "administrator".
    await expect(rows.nth(0)).toContainText('article')
    await expect(rows.nth(0)).toContainText('archive')
    await expect(rows.nth(0).locator('.workflow-guards-role-chip')).toHaveCount(1)
    await expect(rows.nth(0).locator('.workflow-guards-role-chip')).toContainText('administrator')

    // Row 1: publish — two chips, editor + administrator.
    await expect(rows.nth(1).locator('.workflow-guards-role-chip')).toHaveCount(2)
  })

  test('shows the empty state when no guards are configured', async ({ page }) => {
    await page.route('**/api/workflow-definitions/editorial/guards', (route) =>
      route.fulfill({ json: { data: [] } }),
    )

    await page.goto('/workflows/editorial')

    await expect(page.getByTestId('workflow-guards-empty')).toBeVisible()
  })

  test('surfaces the API error detail when the guards request 404s', async ({ page }) => {
    await page.route('**/api/workflow-definitions/editorial/guards', (route) =>
      route.fulfill({
        status: 404,
        json: {
          jsonapi: { version: '1.1' },
          errors: [{ status: '404', title: 'Not Found', detail: 'Workflow "editorial" not found.' }],
        },
      }),
    )

    await page.goto('/workflows/editorial')

    await expect(page.getByTestId('workflow-guards-error')).toContainText('Workflow "editorial" not found.')
  })
})

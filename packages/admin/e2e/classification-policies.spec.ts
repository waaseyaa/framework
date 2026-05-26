// packages/admin/e2e/classification-policies.spec.ts
// Smoke test for /classification/policies (classification-retention-engine-01KSEFTH
// WP04). Requires `nuxt dev` on port 3000; run is deferred per lane-worktree
// convention (FR-014).
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

const samplePolicy = {
  type: 'retention_policy',
  id: 'p1',
  attributes: {
    name: 'Purge old internal notes',
    applies_to: ['internal'],
    action: 'purge',
    trigger_kind: 'age_based',
    trigger_value: 'P90D',
    exemptions: [],
  },
}

test.describe('Classification retention policies', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
  })

  test('shows the empty state when no policies exist', async ({ page }) => {
    await page.route('**/api/classification/policies', route =>
      route.fulfill({ json: { data: [] } }),
    )

    await page.goto('/classification/policies')

    await expect(page.getByTestId('policy-empty')).toBeVisible()
  })

  test('lists policies returned by the API', async ({ page }) => {
    await page.route('**/api/classification/policies', route =>
      route.fulfill({ json: { data: [samplePolicy] } }),
    )

    await page.goto('/classification/policies')

    await expect(page.getByTestId('policy-table')).toBeVisible()
    await expect(page.getByText('Purge old internal notes')).toBeVisible()
  })
})

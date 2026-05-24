// packages/admin/e2e/scheduler.spec.ts
// Smoke test for /scheduler (M4B WP02). Requires `nuxt dev` on port 3000.
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

const sampleTask = {
  name: 'nightly-sync',
  description: 'Nightly content sync',
  expression: '0 2 * * *',
  timezone: null,
  last_run_at: null,
  last_status: null,
  next_run_at: '2026-05-25T02:00:00+00:00',
}

test.describe('Scheduler dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
  })

  test('shows the empty state when no tasks are registered', async ({ page }) => {
    await page.route('**/api/scheduler/tasks', (route) =>
      route.fulfill({ json: { data: [] } }),
    )

    await page.goto('/scheduler')
    await expect(page.getByTestId('scheduler-empty')).toBeVisible()
  })

  test('Run-now requires confirmation, then POSTs to /trigger', async ({ page }) => {
    let triggerCalled = false

    await page.route('**/api/scheduler/tasks', (route) =>
      route.fulfill({
        json: {
          data: [
            triggerCalled
              ? { ...sampleTask, last_status: 'success', last_run_at: '2026-05-24T18:00:00+00:00' }
              : sampleTask,
          ],
        },
      }),
    )
    await page.route('**/api/scheduler/tasks/nightly-sync/trigger', (route) => {
      triggerCalled = true

      return route.fulfill({
        json: { status: 'success', message: 'Task "nightly-sync" completed.' },
      })
    })

    await page.goto('/scheduler')
    await expect(page.getByTestId('scheduler-table')).toBeVisible()
    await page.getByTestId('scheduler-task-trigger').click()
    await expect(page.getByTestId('scheduler-trigger-modal')).toBeVisible()
    expect(triggerCalled).toBe(false)
    await page.getByTestId('scheduler-trigger-confirm').click()
    // After the trigger + refetch, the row should show a "Success" badge.
    await expect(page.getByText('Success')).toBeVisible()
    expect(triggerCalled).toBe(true)
  })
})

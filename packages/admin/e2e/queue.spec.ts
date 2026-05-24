// packages/admin/e2e/queue.spec.ts
// Smoke test for /queue (M4B WP01). Requires `nuxt dev` on port 3000.
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

const sampleJob = {
  id: '42',
  queue: 'default',
  payload: 'O:8:"stdClass":0:{}',
  payload_truncated: false,
  exception_class: 'RuntimeException',
  exception_message: 'boom',
  failed_at: '2026-05-24T00:00:00+00:00',
  attempts: 0,
}

test.describe('Failed jobs queue dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
  })

  test('shows the empty state when no failed jobs exist', async ({ page }) => {
    await page.route('**/api/queue/jobs**', (route) =>
      route.fulfill({
        json: { data: [], meta: { page: 1, per_page: 20, total: 0 } },
      }),
    )

    await page.goto('/queue')
    await expect(page.getByTestId('queue-empty')).toBeVisible()
  })

  test('retry button fires POST /api/queue/jobs/{id}/retry and refetches', async ({ page }) => {
    let listCalls = 0
    let retryCalled = false

    await page.route('**/api/queue/jobs?**', (route) => {
      listCalls += 1
      const jobs = retryCalled ? [] : [sampleJob]

      return route.fulfill({
        json: { data: jobs, meta: { page: 1, per_page: 20, total: jobs.length } },
      })
    })
    await page.route('**/api/queue/jobs/42/retry', (route) => {
      retryCalled = true

      return route.fulfill({ status: 204, body: '' })
    })

    await page.goto('/queue')
    await expect(page.getByTestId('queue-table')).toBeVisible()
    await page.getByTestId('queue-job-retry').click()
    await expect(page.getByTestId('queue-empty')).toBeVisible()
    expect(retryCalled).toBe(true)
    expect(listCalls).toBeGreaterThanOrEqual(2)
  })

  test('discard requires confirmation, then fires POST /discard', async ({ page }) => {
    let discardCalled = false

    await page.route('**/api/queue/jobs?**', (route) =>
      route.fulfill({
        json: {
          data: discardCalled ? [] : [sampleJob],
          meta: { page: 1, per_page: 20, total: discardCalled ? 0 : 1 },
        },
      }),
    )
    await page.route('**/api/queue/jobs/42/discard', (route) => {
      discardCalled = true

      return route.fulfill({ status: 204, body: '' })
    })

    await page.goto('/queue')
    await expect(page.getByTestId('queue-table')).toBeVisible()
    await page.getByTestId('queue-job-discard').click()
    await expect(page.getByTestId('queue-discard-modal')).toBeVisible()
    expect(discardCalled).toBe(false)
    await page.getByTestId('queue-discard-confirm').click()
    await expect(page.getByTestId('queue-empty')).toBeVisible()
    expect(discardCalled).toBe(true)
  })
})

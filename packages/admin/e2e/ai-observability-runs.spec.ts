// packages/admin/e2e/ai-observability-runs.spec.ts
// E2E skeleton for the AI observability runs pages (M5B WP02, #1415).
// Full E2E run requires `nuxt dev` on port 3000 — deferred to CI.
// These tests are skipped by default and serve as a navigation + smoke harness.
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

const mockRunsRoute = async (page: import('@playwright/test').Page): Promise<void> => {
  await page.route('**/api/ai/observability/runs**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          rows: [
            {
              traceUuid: 'trace-1',
              pipeline: 'my-pipeline',
              status: 'ok',
              startedAt: '2026-01-01T10:00:00+00:00',
              endedAt: '2026-01-01T10:00:05+00:00',
              durationMs: 5000,
              costUsd: 0.05,
              totalTokens: 300,
              spanCount: 1,
            },
          ],
          total: 1,
          page: 1,
          perPage: 25,
        },
      }),
    }),
  )
}

const mockRunDetailRoute = async (page: import('@playwright/test').Page): Promise<void> => {
  await page.route('**/api/ai/observability/runs/trace-1', (route) => {
    if (route.request().method() === 'POST') {
      return route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: { newRunUuid: 'new-trace-uuid', status: 'queued', startedAt: '2026-01-01T10:01:00+00:00' },
        }),
      })
    }

    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          header: {
            traceUuid: 'trace-1',
            pipeline: 'my-pipeline',
            status: 'ok',
            startedAt: '2026-01-01T10:00:00+00:00',
            endedAt: '2026-01-01T10:00:05+00:00',
            durationMs: 5000,
            costUsd: 0.05,
            totalTokens: 300,
            spanCount: 1,
          },
          spans: [
            {
              spanUuid: 'root-span',
              parentSpanUuid: null,
              kind: 'agent',
              name: 'root-span-name',
              startedAt: '2026-01-01T10:00:00+00:00',
              endedAt: null,
              status: 'ok',
              attributes: {},
              children: [],
              truncated: false,
            },
          ],
        },
      }),
    })
  })
}

test.describe('AI observability runs', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
    await mockRunsRoute(page)
  })

  test.skip('sidebar has "Recent runs" link under AI section', async ({ page }) => {
    await page.goto('./')
    const link = page.getByTestId('nav-ai-observability-runs')
    await expect(link).toBeVisible()
    await expect(link).toHaveAttribute('href', /\/ai\/observability\/runs/)
  })

  test.skip('runs list page renders table with rows', async ({ page }) => {
    await page.goto('./ai/observability/runs')
    await expect(page.getByTestId('runs-table')).toBeVisible()
    await expect(page.getByTestId('run-list-row').first()).toBeVisible()
  })

  test.skip('runs list page shows empty state when no rows', async ({ page }) => {
    await page.route('**/api/ai/observability/runs**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { rows: [], total: 0, page: 1, perPage: 25 } }),
      }),
    )
    await page.goto('./ai/observability/runs')
    await expect(page.getByTestId('run-list-empty')).toBeVisible()
  })

  test.skip('run detail page renders summary and span tree', async ({ page }) => {
    await mockRunDetailRoute(page)
    await page.goto('./ai/observability/runs/trace-1')
    await expect(page.getByTestId('run-detail-summary')).toBeVisible()
    await expect(page.getByTestId('run-detail-spans')).toBeVisible()
    await expect(page.getByTestId('span-node-root-span')).toBeVisible()
  })

  test.skip('replay button posts and shows new run uuid', async ({ page }) => {
    await mockRunDetailRoute(page)
    await page.goto('./ai/observability/runs/trace-1')
    await page.getByTestId('run-detail-replay-btn').click()
    await expect(page.getByTestId('run-detail-replay-result')).toBeVisible()
    await expect(page.getByTestId('run-detail-replay-result')).toContainText('new-trace-uuid')
  })
})

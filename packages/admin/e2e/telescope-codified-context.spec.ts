// packages/admin/e2e/telescope-codified-context.spec.ts
import { test, expect } from '@playwright/test'

const mockSessions = [
  {
    id: '1',
    sessionId: 'sess-abcdef12',
    repoHash: 'repo-deadbeef',
    startedAt: '2026-03-12T10:00:00Z',
    endedAt: '2026-03-12T10:05:00Z',
    durationMs: 300000,
    eventCount: 42,
    latestDriftScore: 82,
    latestSeverity: 'low',
  },
  {
    id: '2',
    sessionId: 'sess-00112233',
    repoHash: 'repo-cafebabe',
    startedAt: '2026-03-12T09:00:00Z',
    endedAt: null,
    durationMs: null,
    eventCount: 5,
    latestDriftScore: 38,
    latestSeverity: 'high',
  },
]

const mockEvents = [
  {
    id: 'evt-1',
    sessionId: 'sess-abcdef12',
    eventType: 'context.load',
    data: { files: ['docs/specs/entity-system.md', 'CLAUDE.md'] },
    createdAt: '2026-03-12T10:00:01Z',
  },
  {
    id: 'evt-2',
    sessionId: 'sess-abcdef12',
    eventType: 'context.validate',
    data: { trigger: 'manual' },
    createdAt: '2026-03-12T10:01:00Z',
  },
]

const mockValidation = {
  sessionId: 'sess-abcdef12',
  driftScore: 82,
  components: {
    semantic_alignment: 85,
    structural_checks: 90,
    contradiction_checks: 70,
  },
  issues: [
    { type: 'stale_reference', message: 'Spec may be out of date', severity: 'low' },
  ],
  recommendation: 'Context is healthy. Minor stale reference detected.',
  validatedAt: '2026-03-12T10:02:00Z',
}

async function mockAllRoutes(page: import('@playwright/test').Page) {
  await page.route('**/api/telescope/codified-context/sessions**', (route) => {
    const url = route.request().url()
    // Session detail
    if (url.includes('/sessions/sess-abcdef12/events')) {
      return route.fulfill({ json: { data: mockEvents } })
    }
    if (url.includes('/sessions/sess-abcdef12/validation')) {
      return route.fulfill({ json: { data: mockValidation } })
    }
    if (url.includes('/sessions/sess-abcdef12')) {
      return route.fulfill({ json: { data: mockSessions[0] } })
    }
    // Session list
    return route.fulfill({ json: { data: mockSessions } })
  })
}

test.describe('Telescope: Codified Context', () => {
  test.beforeEach(async ({ page }) => {
    await mockAllRoutes(page)
  })

  test('displays session list with sessions', async ({ page }) => {
    await page.goto('/telescope/codified-context')
    await expect(page.getByRole('heading', { name: 'Codified Context' })).toBeVisible()
    await expect(page.getByText('sess-abcd')).toBeVisible()
    await expect(page.getByText('sess-0011')).toBeVisible()
  })

  test('shows severity badges with correct values', async ({ page }) => {
    await page.goto('/telescope/codified-context')
    await expect(page.getByText('low')).toBeVisible()
    await expect(page.getByText('high')).toBeVisible()
  })

  test('shows drift score in session list', async ({ page }) => {
    await page.goto('/telescope/codified-context')
    await expect(page.getByText('82')).toBeVisible()
    await expect(page.getByText('38')).toBeVisible()
  })

  test('navigates to session detail on link click', async ({ page }) => {
    await page.goto('/telescope/codified-context')
    await page.locator(`a[href="/telescope/codified-context/sess-abcdef12"]`).click()
    await expect(page).toHaveURL('/telescope/codified-context/sess-abcdef12')
  })

  test('detail page shows session metadata', async ({ page }) => {
    await page.goto('/telescope/codified-context/sess-abcdef12')
    await expect(page.getByText('sess-abcdef12')).toBeVisible()
    await expect(page.getByText('repo-deadbeef')).toBeVisible()
  })

  test('detail page shows drift score chart', async ({ page }) => {
    await page.goto('/telescope/codified-context/sess-abcdef12')
    // DriftScoreChart renders a progressbar with the score
    await expect(page.getByRole('progressbar')).toBeVisible()
  })

  test('detail page shows validation report with drift score', async ({ page }) => {
    await page.goto('/telescope/codified-context/sess-abcdef12')
    await expect(page.getByText('Validation Report')).toBeVisible()
    // Score appears in validation card
    await expect(page.getByText('Context is healthy. Minor stale reference detected.')).toBeVisible()
  })

  test('detail page shows event stream', async ({ page }) => {
    await page.goto('/telescope/codified-context/sess-abcdef12')
    await expect(page.getByText('Event Stream')).toBeVisible()
    await expect(page.getByText('context.load')).toBeVisible()
    await expect(page.getByText('context.validate')).toBeVisible()
  })

  test('empty state shown when no sessions', async ({ page }) => {
    await page.route('**/api/telescope/codified-context/sessions**', (route) =>
      route.fulfill({ json: { data: [] } }),
    )
    await page.goto('/telescope/codified-context')
    await expect(page.getByText('No codified context sessions recorded yet.')).toBeVisible()
  })
})

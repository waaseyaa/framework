// packages/admin/e2e/mercure-monitor.spec.ts
// Smoke test for /mercure/monitor (M5D WP02). Requires `nuxt dev` on port 3000.
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

const sampleChannels = [
  { channel: 'admin', eventCount24h: 4, lastEventAt: '2026-05-25T00:00:00Z', lastEventName: 'entity.saved' },
  { channel: 'realtime', eventCount24h: 1, lastEventAt: null, lastEventName: null },
]

const sampleEvents = [
  { id: 1, channel: 'admin', event: 'entity.saved', data: { type: 'node' }, createdAt: '2026-05-25T00:00:00Z' },
  { id: 2, channel: 'realtime', event: 'user.connected', data: { uid: 5 }, createdAt: '2026-05-25T00:01:00Z' },
]

const sampleSubscribers = [
  { accountId: 1, accountLabel: 'Admin User', channels: ['admin'], connectedSince: '2026-05-25T00:00:00Z' },
  { accountId: 0, accountLabel: null, channels: ['realtime'], connectedSince: '2026-05-25T00:02:00Z' },
]

test.describe('Broadcast monitor dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)

    await page.route('**/api/mercure/channels', route =>
      route.fulfill({ json: { data: { rows: sampleChannels } } }),
    )
    await page.route('**/api/mercure/events**', route =>
      route.fulfill({ json: { data: { rows: sampleEvents } } }),
    )
    await page.route('**/api/mercure/subscribers', route =>
      route.fulfill({ json: { data: { rows: sampleSubscribers } } }),
    )
  })

  test('shows channel table with event counts', async ({ page }) => {
    await page.goto('/mercure/monitor')
    await expect(page.getByTestId('mercure-channels-table')).toBeVisible()

    const rows = page.getByTestId('mercure-channel-row')
    await expect(rows).toHaveCount(2)
    await expect(rows.first()).toContainText('admin')
    await expect(rows.first()).toContainText('4')
  })

  test('shows recent events table', async ({ page }) => {
    await page.goto('/mercure/monitor')
    await expect(page.getByTestId('mercure-events-table')).toBeVisible()

    const rows = page.getByTestId('mercure-event-row')
    await expect(rows).toHaveCount(2)
    await expect(rows.first()).toContainText('entity.saved')
  })

  test('shows subscribers including anonymous row', async ({ page }) => {
    await page.goto('/mercure/monitor')
    await expect(page.getByTestId('mercure-subscribers-table')).toBeVisible()

    const rows = page.getByTestId('mercure-subscriber-row')
    await expect(rows).toHaveCount(2)
    await expect(rows.first()).toContainText('Admin User')
    await expect(rows.nth(1)).toContainText('Anonymous')
  })

  test('channel filter chip triggers filtered events fetch', async ({ page }) => {
    let markUnfilteredRequestSeen!: () => void
    const unfilteredRequestSeen = new Promise<void>((resolve) => {
      markUnfilteredRequestSeen = resolve
    })
    let releaseUnfilteredResponse!: () => void
    const unfilteredResponseCanFinish = new Promise<void>((resolve) => {
      releaseUnfilteredResponse = resolve
    })

    await page.route('**/api/mercure/events**', async (route) => {
      const requestUrl = new URL(route.request().url())
      const channels = requestUrl.searchParams.getAll('channels')
      if (channels.length === 1 && channels[0] === 'admin') {
        await route.fulfill({ json: { data: { rows: [sampleEvents[0]] } } })
        return
      }

      markUnfilteredRequestSeen()
      await unfilteredResponseCanFinish
      await route.fulfill({ json: { data: { rows: sampleEvents } } })
    })

    try {
      await page.goto('/mercure/monitor')
      await unfilteredRequestSeen

      const filteredRequest = page.waitForRequest((request) => {
        const requestUrl = new URL(request.url())
        const channels = requestUrl.searchParams.getAll('channels')
        return requestUrl.pathname.endsWith('/api/mercure/events')
          && channels.length === 1
          && channels[0] === 'admin'
      })

      await page.getByTestId('mercure-channel-chip-admin').click()

      const requestUrl = new URL((await filteredRequest).url())
      expect(requestUrl.searchParams.getAll('channels')).toEqual(['admin'])
    } finally {
      // Complete the older request only after the filtered assertion. A late
      // unfiltered response therefore cannot overwrite or satisfy its proof.
      releaseUnfilteredResponse()
    }
  })

  test('shows empty state for channels when API returns empty rows', async ({ page }) => {
    await page.route('**/api/mercure/channels', route =>
      route.fulfill({ json: { data: { rows: [] } } }),
    )
    await page.route('**/api/mercure/events**', route =>
      route.fulfill({ json: { data: { rows: [] } } }),
    )
    await page.route('**/api/mercure/subscribers', route =>
      route.fulfill({ json: { data: { rows: [] } } }),
    )

    await page.goto('/mercure/monitor')
    await expect(page.getByTestId('mercure-channels-empty')).toBeVisible()
    await expect(page.getByTestId('mercure-events-empty')).toBeVisible()
    await expect(page.getByTestId('mercure-subscribers-empty')).toBeVisible()
  })

  test('nav link to broadcast monitor is visible', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByTestId('nav-mercure-monitor')).toBeVisible()
  })
})

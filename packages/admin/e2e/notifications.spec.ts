// packages/admin/e2e/notifications.spec.ts
// Smoke test for /notifications (M4C WP01). Requires `nuxt dev` on port 3000.
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'

const sampleChannels = [
  { type: 'mail', class: 'Waaseyaa\\Notification\\Channel\\MailChannel' },
  { type: 'database', class: 'Waaseyaa\\Notification\\Channel\\DatabaseChannel' },
]

test.describe('Notification channels dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
  })

  test('shows the empty state when no channels are registered', async ({ page }) => {
    await page.route('**/api/notification/channels', (route) =>
      route.fulfill({ json: { data: [] } }),
    )

    await page.goto('/notifications')
    await expect(page.getByTestId('notifications-empty')).toBeVisible()
  })

  test('renders the channels table and the help text', async ({ page }) => {
    await page.route('**/api/notification/channels', (route) =>
      route.fulfill({ json: { data: sampleChannels } }),
    )

    await page.goto('/notifications')
    await expect(page.getByTestId('notifications-table')).toBeVisible()
    await expect(page.getByTestId('notifications-help')).toBeVisible()
    await expect(page.getByText('mail', { exact: true })).toBeVisible()
    await expect(page.getByText('database', { exact: true })).toBeVisible()
  })

  test('test button requires confirmation, then fires POST and shows success chip', async ({ page }) => {
    let testCalled = false

    await page.route('**/api/notification/channels', (route) =>
      route.fulfill({ json: { data: sampleChannels } }),
    )
    await page.route('**/api/notification/channels/mail/test', (route) => {
      testCalled = true

      return route.fulfill({
        json: { type: 'mail', status: 'success', message: 'Test sent.' },
      })
    })

    await page.goto('/notifications')
    await expect(page.getByTestId('notifications-table')).toBeVisible()
    await page.getByTestId('notification-channel-test').first().click()
    await expect(page.getByTestId('notifications-confirm-modal')).toBeVisible()
    expect(testCalled).toBe(false)
    await page.getByTestId('notifications-confirm-send').click()
    await expect(page.getByTestId('notifications-result-success')).toBeVisible()
    expect(testCalled).toBe(true)
  })

  test('failure response shows the failure card with exception class', async ({ page }) => {
    await page.route('**/api/notification/channels', (route) =>
      route.fulfill({ json: { data: sampleChannels } }),
    )
    await page.route('**/api/notification/channels/mail/test', (route) =>
      route.fulfill({
        status: 500,
        json: {
          type: 'mail',
          status: 'failed',
          message: 'SMTP unreachable',
          exception_class: 'RuntimeException',
        },
      }),
    )

    await page.goto('/notifications')
    await page.getByTestId('notification-channel-test').first().click()
    await page.getByTestId('notifications-confirm-send').click()
    await expect(page.getByTestId('notifications-result-failure')).toBeVisible()
    await expect(page.getByText('RuntimeException')).toBeVisible()
  })
})

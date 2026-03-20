// packages/admin/e2e/type-lifecycle.spec.ts
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockSchemaRoute, mockEntityListRoute } from './fixtures/routes'

test.describe('Content type lifecycle', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
  })

  test('warns before disabling the last enabled type', async ({ page }) => {
    let noteDisabled = false

    await page.route('**/api/entity-types', (route) =>
      route.fulfill({
        json: {
          data: [
            {
              id: 'note',
              label: 'Note',
              keys: { id: 'id', label: 'title' },
              group: 'content',
              disabled: noteDisabled,
            },
            {
              id: 'node',
              label: 'Content',
              keys: { id: 'id', label: 'title' },
              group: 'content',
              disabled: true,
            },
          ],
        },
      }),
    )

    await page.route('**/api/entity-types/note/disable?force=1', (route) => {
      noteDisabled = true
      route.fulfill({ json: { data: { id: 'note', disabled: true } } })
    })

    await mockSchemaRoute(page, 'note')
    await mockEntityListRoute(page, 'note')

    await page.goto('/note')
    await page.getByRole('button', { name: 'Disable type' }).click()
    await expect(
      page.getByText('This is the last enabled content type. Disabling it may block publishing until another type is enabled.'),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Disable anyway' }).click()
    await expect(page.getByText('Disabled')).toBeVisible()
  })
})

// packages/admin/e2e/type-lifecycle.spec.ts
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockSchemaRoute, mockEntityListRoute } from './fixtures/routes'

test.describe('Content type lifecycle', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
  })

  test('warns before disabling the last enabled type', async ({ page }) => {
    // Override the catalog so note is the only enabled content type.
    // The surface catalog route registered here takes precedence over the
    // one from mockAdminBootstrapRoutes (Playwright runs handlers in LIFO order).
    const lifecycleCatalog = [
      { id: 'note', label: 'Note', group: 'content', fields: [], actions: [], capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true } },
      { id: 'node', label: 'Content', group: 'content', disabled: true, fields: [], actions: [], capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true } },
    ]

    await page.route('**/_surface/catalog', (route) =>
      route.fulfill({
        json: { ok: true, data: { entities: lifecycleCatalog } },
      }),
    )

    // Also override bootstrap entities for consistency
    await page.route('**/admin/bootstrap', (route) =>
      route.fulfill({
        json: {
          version: '1.0',
          auth: { strategy: 'embedded', loginEndpoint: '/api/auth/login' },
          account: { id: String(Number.MAX_SAFE_INTEGER), name: 'dev-admin', roles: ['admin'] },
          tenant: { id: 'default', name: 'Waaseyaa', scopingStrategy: 'server' },
          transport: { strategy: 'jsonapi', apiPath: '/api' },
          entities: lifecycleCatalog.map(({ id, label, group, disabled, capabilities }) => ({
            id, label, group, disabled, capabilities,
          })),
          features: {},
        },
      }),
    )

    // Mock the disable endpoint
    await page.route('**/api/entity-types/note/disable*', (route) =>
      route.fulfill({ json: { data: { id: 'note', disabled: true } } }),
    )

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

// packages/admin/e2e/fixtures/routes.ts
import type { Page } from '@playwright/test'
import { entityTypes } from '../../tests/fixtures/entityTypes'
import { userSchema } from '../../tests/fixtures/schemas'

export async function mockEntityTypesRoute(page: Page) {
  await page.route('**/api/entity-types', (route) =>
    route.fulfill({ json: { data: entityTypes } }),
  )
}

export async function mockSchemaRoute(page: Page, entityType = 'user') {
  await page.route(`**/api/schema/${entityType}`, (route) =>
    route.fulfill({ json: { meta: { schema: userSchema } } }),
  )
}

export async function mockEntityListRoute(page: Page, entityType = 'user') {
  await page.route(`**/api/${entityType}`, (route) =>
    route.fulfill({
      json: {
        jsonapi: { version: '1.0' },
        data: [],
        meta: { total: 0 },
        links: {},
      },
    }),
  )
}

export async function mockEntityCreateRoute(page: Page, entityType = 'user') {
  await page.route(`**/api/${entityType}`, async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        json: {
          jsonapi: { version: '1.0' },
          data: { type: entityType, id: '99', attributes: {} },
        },
      })
    } else {
      await route.continue()
    }
  })
}

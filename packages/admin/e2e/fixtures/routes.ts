// packages/admin/e2e/fixtures/routes.ts
import type { Page } from '@playwright/test'
import { entityTypes } from '../../tests/fixtures/entityTypes'
import { userSchema, noteSchema } from '../../tests/fixtures/schemas'
import type { EntitySchema } from '~/composables/useSchema'

const DEV_ADMIN_ID = String(Number.MAX_SAFE_INTEGER)

export async function mockAdminBootstrapRoutes(page: Page) {
  const session = {
    account: { id: DEV_ADMIN_ID, name: 'dev-admin', email: '', roles: ['admin'] },
    tenant: { id: 'default', name: 'Waaseyaa' },
    policies: [],
    features: {},
  }
  const catalog = entityTypes.map((entry) => ({
    id: entry.id,
    label: entry.label,
    group: entry.group,
    fields: [],
    actions: [],
    capabilities: entry.capabilities,
  }))

  await page.route('**/admin/surface/session', (route) =>
    route.fulfill({
      json: { ok: true, data: session },
    }),
  )

  await page.route('**/admin/surface/catalog', (route) =>
    route.fulfill({
      json: { ok: true, data: { entities: catalog } },
    }),
  )

  await page.route('**/admin/bootstrap', (route) =>
    route.fulfill({
      json: {
        version: '1.0',
        auth: { strategy: 'embedded', loginEndpoint: '/api/auth/login' },
        account: session.account,
        tenant: { ...session.tenant, scopingStrategy: 'server' },
        transport: { strategy: 'jsonapi', apiPath: '/api' },
        entities: entityTypes,
        features: session.features,
      },
    }),
  )

  await page.route('**/api/user/me', (route) =>
    route.fulfill({
      json: {
        jsonapi: { version: '1.1' },
        data: { id: DEV_ADMIN_ID, name: 'dev-admin', email: '', roles: ['admin'] },
      },
    }),
  )
}

export async function mockEntityTypesRoute(page: Page) {
  await page.route('**/api/entity-types', (route) =>
    route.fulfill({ json: { data: entityTypes } }),
  )
}

export async function mockSchemaRoute(page: Page, entityType = 'user', schema?: EntitySchema) {
  const resolved = schema ?? (entityType === 'note' ? noteSchema : userSchema)
  // Surface transport: POST /admin/surface/{type}/action/schema
  await page.route(`**/admin/surface/${entityType}/action/schema`, (route) =>
    route.fulfill({ json: { ok: true, data: resolved } }),
  )
  // Legacy JSON API fallback
  await page.route(`**/api/schema/${entityType}`, (route) =>
    route.fulfill({ json: { meta: { schema: resolved } } }),
  )
}

export async function mockEntityListRoute(page: Page, entityType = 'user') {
  // Surface transport: GET /admin/surface/{type}
  await page.route(`**/admin/surface/${entityType}`, (route) => {
    if (route.request().method() === 'GET') {
      return route.fulfill({
        json: { ok: true, data: { entities: [], total: 0, offset: 0, limit: 25 } },
      })
    }
    return route.fallback()
  })
  // Legacy JSON API fallback
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
  // Surface transport: POST /admin/surface/{type}/action/create
  await page.route(`**/admin/surface/${entityType}/action/create`, (route) =>
    route.fulfill({
      json: { ok: true, data: { type: entityType, id: '99', attributes: {} } },
    }),
  )
  // Legacy JSON API fallback
  await page.route(`**/api/${entityType}`, async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        json: {
          jsonapi: { version: '1.0' },
          data: { type: entityType, id: '99', attributes: {} },
        },
      })
    } else {
      await route.fallback()
    }
  })
}

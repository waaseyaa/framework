import { expect, test, type Locator, type Page } from '@playwright/test'
import type { EntitySchema } from '~/composables/useSchema'
import { mockAdminBootstrapRoutes, mockSchemaRoute } from './fixtures/routes'

const viewports = [360, 768, 1024, 1440]

const targetSchema: EntitySchema = {
  $schema: 'http://json-schema.org/draft-07/schema#',
  title: 'Record',
  type: 'object',
  'x-entity-type': 'user',
  'x-translatable': false,
  'x-revisionable': false,
  properties: {
    title: { type: 'string', 'x-widget': 'text', 'x-label': 'Title', 'x-weight': 0 },
    kind: {
      type: 'string',
      'x-widget': 'select',
      'x-label': 'Kind',
      'x-weight': 1,
      enum: ['story', 'notice'],
      'x-enum-labels': { story: 'Story', notice: 'Notice' },
    },
    publish_on: { type: 'string', 'x-widget': 'date', 'x-label': 'Publish on', 'x-weight': 2 },
    body: { type: 'string', 'x-widget': 'richtext', 'x-label': 'Body', 'x-weight': 3 },
    related: {
      type: 'string',
      'x-widget': 'entity_autocomplete',
      'x-label': 'Related record',
      'x-target-type': 'user',
      'x-cardinality': 1,
      'x-weight': 4,
    },
    active: { type: 'boolean', 'x-widget': 'boolean', 'x-label': 'Active', 'x-weight': 5 },
    locked: { type: 'string', 'x-widget': 'text', 'x-label': 'Locked', 'x-weight': 6, 'x-access-restricted': true },
  },
}

async function mockTargetApp(page: Page) {
  await mockAdminBootstrapRoutes(page)
  await page.route('**/_surface/session', route => route.fulfill({
    json: {
      ok: true,
      data: {
        account: { id: '1', name: 'admin', email: '', roles: ['admin'] },
        tenant: { id: 'default', name: 'Waaseyaa' },
        policies: [],
        features: {},
        ui: { navigationMode: 'catalog-only', headerLinks: [], sidebarItems: [] },
      },
    },
  }))
  await page.route('**/_surface/catalog', route => route.fulfill({
    json: {
      ok: true,
      data: {
        entities: [{
          id: 'user',
          label: 'Records',
          group: 'content',
          fields: [],
          actions: [],
          capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
          reference: {
            labelField: 'title',
            search: { field: 'title', operator: 'STARTS_WITH' },
            sort: { field: 'title', direction: 'ASC' },
          },
        }],
      },
    },
  }))
  await mockSchemaRoute(page, 'user', targetSchema)
  await page.route('**/_surface/user/1', route => route.fulfill({
    json: { ok: true, data: { type: 'user', id: '1', attributes: { title: 'Example record' } } },
  }))
  await page.route(/\/_surface\/user(?:\?.*)?$/, route => route.fulfill({
    json: {
      ok: true,
      data: {
        entities: [{ type: 'user', id: '2', attributes: { title: 'Related example' } }],
        total: 1,
        offset: 0,
        limit: 10,
      },
    },
  }))
}

async function targetBox(locator: Locator) {
  return locator.evaluate((element) => {
    const box = element.getBoundingClientRect()
    return { width: box.width, height: box.height, left: box.left, right: box.right, top: box.top, bottom: box.bottom }
  })
}

async function expectTarget(locator: Locator) {
  const box = await targetBox(locator)
  expect(box.width).toBeGreaterThanOrEqual(44)
  expect(box.height).toBeGreaterThanOrEqual(44)
  return box
}

test.describe('complete admin target-size contract', () => {
  test.beforeEach(async ({ page }) => {
    await mockTargetApp(page)
  })

  for (const width of viewports) {
    test(`${width}px contains shared targets and enlarged text`, async ({ page }) => {
      await page.setViewportSize({ width, height: 1000 })
      await page.goto('/user/create')
      await expect(page.getByLabel('Title')).toBeVisible()

      await page.locator('.content').evaluate((content) => {
        const link = document.createElement('a')
        link.href = '#inline-action'
        link.className = 'target-contract-inline-link'
        link.textContent = 'Inline action'
        content.prepend(link)
      })
      await expectTarget(page.locator('.target-contract-inline-link'))

      await expectTarget(page.locator('.topbar-brand'))
      await expectTarget(page.locator('.topbar-locale-select'))

      if (width <= 768) {
        await expectTarget(page.locator('.topbar-toggle'))
        await page.locator('.topbar-toggle').click()
        await expectTarget(page.locator('.sidebar-close'))
        await page.locator('.sidebar-close').click()
      }
      await expectTarget(page.locator('.nav-item'))

      for (const control of [
        page.getByLabel('Title'),
        page.getByLabel('Kind'),
        page.getByLabel('Publish on'),
        page.getByLabel('Locked'),
        page.getByRole('button', { name: 'Edit HTML source' }),
        page.getByRole('button', { name: /create/i }),
        page.getByRole('link', { name: /back to list/i }),
      ]) await expectTarget(control)

      const toggleLabel = page.locator('.toggle-label')
      await expectTarget(toggleLabel)
      await toggleLabel.click({ position: { x: 40, y: 22 } })
      await expect(page.getByLabel('Active')).toBeChecked()

      const autocomplete = page.getByRole('combobox', { name: 'Related record' })
      await expectTarget(autocomplete)
      await autocomplete.fill('Rel')
      await expect(page.getByRole('option', { name: 'Related example' })).toBeVisible()
      const inputBox = await targetBox(autocomplete)
      const clearBox = await expectTarget(page.locator('.autocomplete-clear'))
      await expectTarget(page.getByRole('option', { name: 'Related example' }))
      expect(Math.min(inputBox.right, clearBox.right) - Math.max(inputBox.left, clearBox.left)).toBeLessThanOrEqual(0)

      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(width)

      await page.goto('/user/1')
      const disclosure = page.locator('.btn-link')
      await expect(disclosure).toBeVisible()
      await expectTarget(disclosure)
      await disclosure.click()
      await expect(disclosure).toHaveText(/hide/i)

      await page.evaluate(() => { document.documentElement.style.fontSize = '200%' })
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(width)
    })
  }
})

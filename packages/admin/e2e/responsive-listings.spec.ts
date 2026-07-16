import { test, expect, type Page } from '@playwright/test'
import type { EntitySchema } from '~/composables/useSchema'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute, mockSchemaRoute } from './fixtures/routes'

const widths = [360, 768, 1024, 1440]

const wideSchema: EntitySchema = {
  $schema: 'http://json-schema.org/draft-07/schema#',
  title: 'Responsive records',
  type: 'object',
  'x-entity-type': 'user',
  'x-translatable': false,
  'x-revisionable': false,
  properties: Object.fromEntries(Array.from({ length: 8 }, (_, index) => [
    `field_${index + 1}`,
    { type: 'string', 'x-widget': 'text', 'x-label': `Field ${index + 1}`, 'x-weight': index },
  ])),
}

async function mockWideListing(page: Page) {
  const entities = Array.from({ length: 25 }, (_, row) => ({
    type: 'user',
    id: `row-${row + 1}`,
    attributes: Object.fromEntries(Array.from({ length: 8 }, (_, column) => [
      `field_${column + 1}`,
      column === 0 ? `row-${row}-${'unbroken'.repeat(30)}` : `value-${row}-${column}`,
    ])),
  }))

  await page.route(/\/_surface\/user(?:\?.*)?$/, route => route.fulfill({
    json: { ok: true, data: { entities, total: 400, offset: 0, limit: 25 } },
  }))
}

test.describe('Responsive listing layout', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)
    await mockSchemaRoute(page, 'user', wideSchema)
    await mockWideListing(page)
  })

  for (const width of widths) {
    test(`${width}px contains arbitrary columns, pagination, actions, and enlarged text`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 })
      await page.goto('/user')
      await expect(page.locator('[data-testid="listing-scroll"]')).toBeVisible()

      const measurements = await page.evaluate(() => {
        const scroller = document.querySelector<HTMLElement>('[data-testid="listing-scroll"]')!
        const rows = [...document.querySelectorAll('tbody tr[data-row-id]')]
        const targets = [...document.querySelectorAll<HTMLElement>('tbody .actions .btn, .pagination .btn')]
        return {
          viewport: innerWidth,
          documentWidth: document.documentElement.scrollWidth,
          scrollerClientWidth: scroller.clientWidth,
          scrollerScrollWidth: scroller.scrollWidth,
          rowDisplay: rows[0] ? getComputedStyle(rows[0]).display : null,
          duplicateActionGroups: rows.filter(row => row.querySelectorAll('.actions').length !== 1).length,
          minimumTargetWidth: Math.min(...targets.map(target => target.getBoundingClientRect().width)),
          minimumTargetHeight: Math.min(...targets.map(target => target.getBoundingClientRect().height)),
          currentPages: document.querySelectorAll('.pagination [aria-current="page"]').length,
          pageButtons: document.querySelectorAll('.pagination [data-page]').length,
        }
      })

      expect(measurements.documentWidth).toBe(measurements.viewport)
      expect(measurements.scrollerScrollWidth).toBeGreaterThanOrEqual(measurements.scrollerClientWidth)
      expect(measurements.duplicateActionGroups).toBe(0)
      expect(measurements.minimumTargetWidth).toBeGreaterThanOrEqual(44)
      expect(measurements.minimumTargetHeight).toBeGreaterThanOrEqual(44)
      expect(measurements.currentPages).toBe(1)
      expect(measurements.pageButtons).toBeGreaterThanOrEqual(3)
      expect(measurements.pageButtons).toBeLessThanOrEqual(5)
      expect(measurements.rowDisplay).toBe(width <= 600 ? 'block' : 'table-row')

      await page.evaluate(() => { document.documentElement.style.fontSize = '200%' })
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(width)
    })
  }

  test('closed mobile navigation is unreachable and Escape restores the opener', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 900 })
    await page.goto('/user')
    const toggle = page.locator('.topbar-toggle')
    const sidebar = page.locator('#admin-sidebar')

    await expect(sidebar).toHaveAttribute('inert', '')
    await expect(sidebar).toHaveAttribute('aria-hidden', 'true')
    await toggle.focus()
    await page.keyboard.press('Tab')
    expect(await sidebar.evaluate(element => element.contains(document.activeElement))).toBe(false)

    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-expanded', 'true')
    await expect(sidebar).not.toHaveAttribute('inert', '')
    await expect(page.locator('.sidebar-close')).toBeFocused()
    const closeBox = await page.locator('.sidebar-close').boundingBox()
    expect(closeBox?.width).toBeGreaterThanOrEqual(44)
    expect(closeBox?.height).toBeGreaterThanOrEqual(44)

    await page.keyboard.press('Escape')
    await expect(toggle).toHaveAttribute('aria-expanded', 'false')
    await expect(sidebar).toHaveAttribute('inert', '')
    await expect(toggle).toBeFocused()
  })
})

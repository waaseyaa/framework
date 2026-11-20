import { expect, test } from '@playwright/test'
import { noteSchema } from '../tests/fixtures/schemas'
import { mockAdminBootstrapRoutes, mockSchemaRoute } from './fixtures/routes'

test.beforeEach(async ({ page }) => {
  await mockAdminBootstrapRoutes(page)
  await mockSchemaRoute(page, 'note', {
    ...noteSchema,
    properties: {
      ...noteSchema.properties,
      body: { ...noteSchema.properties.body, 'x-widget': 'richtext' },
    },
  })
  await page.goto('/note/create')
})

test('creates, edits, and removes a link without losing the selected text', async ({ page }) => {
  const editor = page.getByRole('textbox', { name: 'Body' })
  await editor.fill('Read this guide.')
  await editor.press('End')
  await editor.press('ArrowLeft')
  for (const _ of 'guide') await editor.press('Shift+ArrowLeft')
  await expect.poll(() => page.evaluate(() => window.getSelection()?.toString())).toBe('guide')

  const linkButton = page.getByRole('button', { name: 'Link', exact: true })
  await linkButton.focus()
  await page.keyboard.press('Enter')
  const address = page.getByRole('textbox', { name: 'Link address' })
  await expect(address).toBeFocused()
  await editor.press('Home')
  for (const _ of 'Read') await editor.press('Shift+ArrowRight')
  await expect.poll(() => page.evaluate(() => window.getSelection()?.toString())).toBe('Read')
  await address.fill('https://example.test/guide')
  await page.getByRole('button', { name: 'Apply', exact: true }).click()

  const link = editor.getByRole('link', { name: 'guide' })
  await expect(link).toHaveAttribute('href', 'https://example.test/guide')
  await expect(editor).toHaveText('Read this guide.')

  await link.click()
  await expect(page).toHaveURL(/\/note\/create$/)
  await expect(address).toHaveValue('https://example.test/guide')
  await address.fill('https://example.test/updated')
  await address.press('Enter')
  await expect(link).toHaveAttribute('href', 'https://example.test/updated')

  await link.click()
  await page.getByRole('button', { name: 'Remove link' }).click()
  await expect(link).toHaveCount(0)
  await expect(editor).toHaveText('Read this guide.')
})

test('keeps a mixed link and text selection intact when opening and canceling link entry', async ({ page }) => {
  await page.getByRole('button', { name: 'Edit HTML source' }).click()
  await page.getByRole('textbox', { name: 'Body' }).fill('<p><a href="/guide">Guide</a> notes</p>')
  await page.getByRole('button', { name: 'Return to visual editor' }).click()

  const editor = page.getByRole('textbox', { name: 'Body' })
  await editor.press('ControlOrMeta+a')
  await expect.poll(() => page.evaluate(() => window.getSelection()?.toString())).toBe('Guide notes')
  await page.getByRole('button', { name: 'Link', exact: true }).click()

  const address = page.getByRole('textbox', { name: 'Link address' })
  await expect(address).toHaveValue('')
  await address.press('Escape')
  await expect(editor).toBeFocused()
  await expect.poll(() => page.evaluate(() => window.getSelection()?.toString())).toBe('Guide notes')
  await expect(editor.getByRole('link', { name: 'Guide' })).toHaveAttribute('href', '/guide')
})

test('formats text and switches block and list types in the visual editor', async ({ page }) => {
  const editor = page.getByRole('textbox', { name: 'Body' })
  const toolbar = page.getByRole('toolbar', { name: 'Rich text editing tools' })
  await editor.fill('Meeting notes')
  await editor.press('Home')
  await editor.press('Shift+End')

  await toolbar.getByRole('button', { name: 'Bold', exact: true }).click()
  await expect(editor.locator('b, strong')).toHaveText('Meeting notes')
  await toolbar.getByRole('button', { name: 'Italic', exact: true }).click()
  await expect(editor.locator('i, em')).toHaveText('Meeting notes')

  await toolbar.getByRole('button', { name: 'Heading', exact: true }).click()
  await expect(editor.getByRole('heading', { name: 'Meeting notes' })).toBeVisible()
  await toolbar.getByRole('button', { name: 'Paragraph', exact: true }).click()
  await expect(editor.locator('p')).toHaveText('Meeting notes')

  await toolbar.getByRole('button', { name: 'Bulleted list', exact: true }).click()
  await expect(editor.locator('ul > li')).toHaveText('Meeting notes')
  await toolbar.getByRole('button', { name: 'Numbered list', exact: true }).click()
  await expect(editor.locator('ol > li')).toHaveText('Meeting notes')
})

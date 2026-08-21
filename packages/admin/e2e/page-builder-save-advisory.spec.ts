// packages/admin/e2e/page-builder-save-advisory.spec.ts
import { test, expect, type Page, type Route } from '@playwright/test'
import { mockAdminBootstrapRoutes } from './fixtures/routes'

/**
 * The layout save-advisory review (#2475), driven through the real editor.
 *
 * A page-builder surface is registered by the *application*, not the framework,
 * so there is no live surface to point at here. The command endpoint is served
 * from this spec instead, with the exact envelopes the PHP host emits — the
 * shapes `AdminSpaLayoutAdvisoryContractTest` pins against the real host.
 */

const TOKEN = 'b'.repeat(64)
const OTHER_TOKEN = 'c'.repeat(64)

const definitions = {
  blocks: [{
    id: 'rich_text',
    version: 1,
    label: 'Rich text',
    renderer: 'content.rich_text',
    config_schema: { type: 'object', properties: { body: { type: 'string', title: 'Body' } } },
  }],
  layouts: [{ id: 'one_column', version: 1, regions: ['main'], required_regions: ['main'], allowed_blocks: ['rich_text'] }],
  templates: [{ id: 'standard', version: 1, allowed_layouts: ['one_column'], allowed_blocks: ['rich_text'] }],
}

const draft = {
  entity_id: '42',
  entity_revision_id: 7,
  document_fingerprint: 'a'.repeat(64),
  document: {
    schema: 'waaseyaa.layout',
    version: 1,
    template: { id: 'standard', version: 1 },
    sections: [{
      id: 'sec_body',
      layout: { id: 'one_column', version: 1 },
      regions: { main: [{ id: 'blk_body', type: 'rich_text', version: 1, config: { body: 'Before' } }] },
    }],
  },
}

function heldEnvelope(acknowledgement = TOKEN) {
  return {
    ok: false,
    error: {
      status: 428,
      title: 'Precondition Required',
      detail: 'Review and acknowledge the save advisory before retrying.',
      code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
      meta: {
        save_advisories: [{
          code: 'RESERVED_ROUTE_VALUE',
          field: 'slug',
          severity: 'warning',
          message: 'This slug is reserved for a system route.',
          acknowledgement,
        }],
      },
    },
  }
}

const unsupportedEnvelope = {
  ok: false,
  error: {
    status: 501,
    title: 'Save advisory acknowledgement unsupported',
    detail: 'This layout draft surface cannot accept save advisory acknowledgements.',
    code: 'SAVE_ADVISORY_UNSUPPORTED',
  },
}

/** Records every command body the editor actually sent. */
type CommandLog = Array<Record<string, unknown>>

async function mockPageBuilder(
  page: Page,
  respond: (body: Record<string, unknown>, index: number) => unknown,
): Promise<CommandLog> {
  const sent: CommandLog = []
  await page.route('**/_surface/page-builder/*/definitions', route =>
    route.fulfill({ json: { ok: true, data: { definitions } } }))
  await page.route('**/_surface/page-builder/*/42', route =>
    route.fulfill({ json: { ok: true, data: draft } }))
  await page.route('**/_surface/page-builder/*/42/revisions', route =>
    route.fulfill({ json: { ok: true, data: { revisions: [] } } }))
  await page.route('**/_surface/page-builder/*/42/preview', route =>
    route.fulfill({ json: { ok: true, data: { entity_id: '42', revision_id: 7, expires_at: 0, signature: 'x', preview_url: '/preview/42' } } }))
  await page.route('**/_surface/page-builder/*/42/commands', (route: Route) => {
    const body = route.request().postDataJSON() as Record<string, unknown>
    const index = sent.length
    sent.push(body)
    route.fulfill({ json: respond(body, index) })
  })
  return sent
}

const APPLY = 'Apply as new revision'

async function editSelectedBlock(page: Page) {
  await page.goto('/page-builder/pages/42')
  await page.locator('[data-block-select="blk_body"]').click()
  await page.getByRole('textbox', { name: 'Body' }).fill('Rewritten body')
  await page.getByRole('button', { name: APPLY }).click()
}

async function openHeldEditor(page: Page, sent: CommandLog) {
  await editSelectedBlock(page)
  await expect(page.locator('[data-page-builder-advisory]')).toBeVisible()
  expect(sent).toHaveLength(1)
}

test.describe('Page-builder layout save advisory', () => {
  test.beforeEach(async ({ page }) => {
    await mockAdminBootstrapRoutes(page)
  })

  test('renders the advisory and keeps the pending edit when a save is held', async ({ page }) => {
    const sent = await mockPageBuilder(page, () => heldEnvelope())
    await openHeldEditor(page, sent)

    const banner = page.locator('[data-page-builder-advisory]')
    await expect(banner).toContainText('slug')
    await expect(banner).toContainText('This slug is reserved for a system route.')
    await expect(banner).not.toContainText(TOKEN)
    // The edit was not discarded and the first attempt carried no receipt.
    await expect(page.getByRole('textbox', { name: 'Body' })).toHaveValue('Rewritten body')
    expect(sent[0]).not.toHaveProperty('save_advisory_acknowledgements')
  })

  test('returns exactly the received receipt on the same bound retry', async ({ page }) => {
    const sent = await mockPageBuilder(page, (_body, index) => index === 0
      ? heldEnvelope()
      : { ok: true, data: { ...draft, entity_revision_id: 8, document_fingerprint: 'd'.repeat(64) } })
    await openHeldEditor(page, sent)

    await page.locator('[data-page-builder-advisory-confirm]').click()
    await expect(page.locator('[data-page-builder-advisory]')).toBeHidden()

    expect(sent).toHaveLength(2)
    expect(sent[1]!.save_advisory_acknowledgements).toEqual([TOKEN])
    expect(sent[1]!.command).toEqual(sent[0]!.command)
    expect(sent[1]!.expected_document_fingerprint).toBe(draft.document_fingerprint)
    expect(sent[1]!.expected_entity_revision_id).toBe(draft.entity_revision_id)
  })

  test('re-prompts with the new advisory instead of replaying a superseded receipt', async ({ page }) => {
    const sent = await mockPageBuilder(page, (_body, index) => index === 0
      ? heldEnvelope()
      : index === 1
        ? heldEnvelope(OTHER_TOKEN)
        : { ok: true, data: { ...draft, entity_revision_id: 8 } })
    await openHeldEditor(page, sent)

    await page.locator('[data-page-builder-advisory-confirm]').click()
    await expect(page.locator('[data-page-builder-advisory]')).toBeVisible()
    await page.locator('[data-page-builder-advisory-confirm]').click()
    await expect(page.locator('[data-page-builder-advisory]')).toBeHidden()

    expect(sent).toHaveLength(3)
    expect(sent[1]!.save_advisory_acknowledgements).toEqual([TOKEN])
    expect(sent[2]!.save_advisory_acknowledgements).toEqual([OTHER_TOKEN])
  })

  test('declining writes nothing and leaves the editor intact', async ({ page }) => {
    const sent = await mockPageBuilder(page, () => heldEnvelope())
    await openHeldEditor(page, sent)

    await page.locator('[data-page-builder-advisory-decline]').click()
    await expect(page.locator('[data-page-builder-advisory]')).toBeHidden()

    expect(sent).toHaveLength(1)
    await expect(page.getByRole('textbox', { name: 'Body' })).toHaveValue('Rewritten body')
  })

  test('presents an unsupported deployment with no confirm affordance', async ({ page }) => {
    const sent = await mockPageBuilder(page, () => unsupportedEnvelope)
    await editSelectedBlock(page)

    const banner = page.locator('[data-page-builder-advisory-unsupported]')
    await expect(banner).toBeVisible()
    await expect(banner.locator('button')).toHaveCount(0)
    await expect(page.locator('[data-page-builder-advisory-confirm]')).toHaveCount(0)
    expect(sent).toHaveLength(1)
  })
})

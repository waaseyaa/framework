// packages/admin/tests/components/schema/SchemaListColumnPolicy.test.ts
//
// Acceptance test for the list-view column policy (UX-1). A content type with a
// long-text / rich-text body field must render a list table whose columns are
// BOUNDED — the full body is never dumped into a cell:
//   1. rich-text / text-format fields (x-widget 'richtext') are dropped from the
//      default column set entirely (they remain on the detail/edit views);
//   2. every cell value is collapsed to one line and truncated to a snippet, so
//      a long plain-text field (x-widget 'textarea') that stays a column — or a
//      rich-text field an author explicitly opts back in with x-list-display —
//      is still bounded.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'

const { ref } = require('vue') as typeof import('vue')

// Snippet cap mirrors SNIPPET_MAX_CHARS in SchemaList.vue.
const SNIPPET_MAX_CHARS = 120
const LONG_TEXT = 'Long plain-text body. ' + 'word '.repeat(200) // » 120 chars
const RICH_BODY = '<p>' + 'Rich body paragraph. '.repeat(200) + '</p>' // » 120 chars

// Mutable state the hoisted composable mocks read, so each test can drive a
// different schema shape + row without re-declaring the (hoisted) vi.mock.
const { schemaState, entityState } = vi.hoisted(() => ({
  schemaState: { props: [] as Array<[string, Record<string, unknown>]> },
  entityState: { row: {} as Record<string, unknown> },
}))

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({ hasCapability: () => true }),
}))

vi.mock('~/composables/useAdminConfig', () => ({
  useAdminConfig: () => ({ enableRealtime: false }),
}))

vi.mock('~/composables/useRealtime', () => ({
  useRealtime: () => ({
    messages: ref([]),
    connected: ref(false),
    error: ref(null),
    connect: vi.fn(),
    disconnect: vi.fn(),
    reconnect: vi.fn(),
  }),
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key, entityLabel: (_t: string, fb: string) => fb }),
}))

vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({
    schema: ref({ title: 'Content', properties: {} }),
    loading: ref(false),
    fetch: vi.fn().mockResolvedValue(undefined),
    sortedProperties: () => schemaState.props,
  }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({
    list: vi.fn().mockImplementation(async () => ({
      data: [{ type: 'story', id: '1', attributes: entityState.row }],
      meta: { total: 1 },
    })),
    remove: vi.fn(),
  }),
}))

const NuxtLinkStub = {
  props: ['to'],
  template: '<a :href="to"><slot /></a>',
}

async function mountList() {
  const { default: SchemaList } = await import('~/components/schema/SchemaList.vue')
  const wrapper = await mountSuspended(SchemaList, {
    props: { entityType: 'story' },
    global: { stubs: { NuxtLink: NuxtLinkStub } },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  schemaState.props = []
  entityState.row = {}
})

describe('SchemaList list-view column policy (UX-1)', () => {
  it('drops the rich-text column and never dumps the full body into a cell', async () => {
    schemaState.props = [
      ['title', { 'x-widget': 'text', 'x-label': 'Title' }],
      ['summary', { 'x-widget': 'textarea', 'x-label': 'Summary' }],
      ['body', { 'x-widget': 'richtext', 'x-label': 'Body' }],
    ]
    entityState.row = { title: 'Hello', summary: LONG_TEXT, body: RICH_BODY }

    const wrapper = await mountList()

    // The rich-text body is excluded from the default columns entirely...
    expect(wrapper.find('[data-anchor="list-field:story:body"]').exists()).toBe(false)
    // ...while the short text + long-text columns remain.
    expect(wrapper.find('[data-anchor="list-field:story:title"]').exists()).toBe(true)
    expect(wrapper.find('[data-anchor="list-field:story:summary"]').exists()).toBe(true)

    // Header count: 2 data columns + 1 actions column (body gone).
    expect(wrapper.findAll('thead th')).toHaveLength(3)

    // The full body / long-text is never present anywhere in the rendered table.
    const html = wrapper.html()
    expect(html).not.toContain(RICH_BODY)
    expect(html).not.toContain(LONG_TEXT)
  })

  it('truncates a long plain-text cell to a bounded snippet', async () => {
    schemaState.props = [
      ['title', { 'x-widget': 'text', 'x-label': 'Title' }],
      ['summary', { 'x-widget': 'textarea', 'x-label': 'Summary' }],
    ]
    entityState.row = { title: 'Hello', summary: LONG_TEXT }

    const wrapper = await mountList()

    // Data cells of the single row: [title, summary, actions].
    const cells = wrapper.findAll('tbody tr td')
    const summaryText = cells[1]!.text()

    expect(summaryText).not.toBe(LONG_TEXT)
    expect(summaryText.length).toBeLessThanOrEqual(SNIPPET_MAX_CHARS + 1) // + ellipsis
    expect(summaryText.endsWith('…')).toBe(true)
  })

  it('honors an explicit x-list-display opt-in but still truncates the cell', async () => {
    // An author can force a rich-text field back into the list — it then renders
    // as a column, but the cell is still snippet-bounded (never a full dump).
    schemaState.props = [
      ['title', { 'x-widget': 'text', 'x-label': 'Title' }],
      ['body', { 'x-widget': 'richtext', 'x-label': 'Body', 'x-list-display': true }],
    ]
    entityState.row = { title: 'Hello', body: RICH_BODY }

    const wrapper = await mountList()

    // Explicit opt-in wins: only the opted-in column is shown.
    expect(wrapper.find('[data-anchor="list-field:story:body"]').exists()).toBe(true)
    expect(wrapper.find('[data-anchor="list-field:story:title"]').exists()).toBe(false)

    const cells = wrapper.findAll('tbody tr td')
    const bodyText = cells[0]!.text()
    expect(bodyText).not.toBe(RICH_BODY)
    expect(bodyText.length).toBeLessThanOrEqual(SNIPPET_MAX_CHARS + 1)
    expect(bodyText.endsWith('…')).toBe(true)
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'

const { ref } = require('vue') as typeof import('vue')

const { state } = vi.hoisted(() => ({
  state: {
    schemaLoading: false,
    listError: false,
    rows: [] as Array<{ type: string, id: string, attributes: Record<string, unknown> }>,
    total: 0,
  },
}))

const fields = Array.from({ length: 8 }, (_, index) => [
  `field_${index + 1}`,
  { type: 'string', 'x-widget': 'text', 'x-label': `Field ${index + 1}` },
] as [string, Record<string, unknown>])

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({ hasCapability: () => true }),
}))

vi.mock('~/composables/useAdminConfig', () => ({
  useAdminConfig: () => ({ enableRealtime: false }),
}))

vi.mock('~/composables/useRealtime', () => ({
  useRealtime: () => ({
    messages: ref([]), connected: ref(false), error: ref(null),
    connect: vi.fn(), disconnect: vi.fn(), reconnect: vi.fn(),
  }),
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string, values?: Record<string, unknown>) => values ? `${key}:${JSON.stringify(values)}` : key }),
}))

vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({
    schema: ref({ title: 'Arbitrary records', properties: {} }),
    loading: ref(state.schemaLoading),
    fetch: vi.fn().mockResolvedValue(undefined),
    sortedProperties: () => fields,
  }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({
    list: vi.fn().mockImplementation(async () => {
      if (state.listError) throw new Error('Listing unavailable')
      return { data: state.rows, meta: { total: state.total } }
    }),
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
    props: { entityType: 'arbitrary_record' },
    global: { stubs: { NuxtLink: NuxtLinkStub } },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  state.schemaLoading = false
  state.listError = false
  state.rows = []
  state.total = 0
})

describe('SchemaList responsive contract', () => {
  it('uses one labelled semantic table/card presentation with contained long values and one action set', async () => {
    state.rows = [{
      type: 'arbitrary_record',
      id: 'row-1',
      attributes: Object.fromEntries(fields.map(([name], index) => [name, index === 0 ? 'A'.repeat(500) : `value-${index}`])),
    }]
    state.total = 1

    const wrapper = await mountList()
    const region = wrapper.get('[data-testid="listing-region"]')
    const scroller = wrapper.get('[data-testid="listing-scroll"]')
    expect(region.attributes('aria-label')).toBeTruthy()
    expect(scroller.attributes('role')).toBe('region')
    expect(scroller.attributes('tabindex')).toBe('0')
    expect(scroller.attributes('aria-label')).toBeTruthy()
    expect(wrapper.get('table').attributes('data-responsive-mode')).toBe('table-card')
    expect(wrapper.findAll('thead th[scope="col"]')).toHaveLength(7)

    const row = wrapper.get('tbody tr[data-row-id="row-1"]')
    expect(row.attributes('aria-label')).toBeTruthy()
    const dataCells = row.findAll('td[data-label]')
    expect(dataCells).toHaveLength(7)
    expect(dataCells[0]?.attributes('data-label')).toBe('Field 1')
    expect(dataCells[0]?.text().length).toBeLessThan(500)
    expect(row.findAll('[data-anchor="action:arbitrary_record:edit"]')).toHaveLength(1)
    expect(row.findAll('[data-anchor="action:arbitrary_record:delete"]')).toHaveLength(1)
    expect(row.get('.actions').attributes('data-label')).toBe('actions')
  })

  it('keeps empty, loading, and failure states inside the responsive listing region', async () => {
    const empty = await mountList()
    expect(empty.get('[data-testid="listing-region"] [data-testid="listing-scroll"] .empty').exists()).toBe(true)

    state.schemaLoading = true
    const loading = await mountList()
    expect(loading.get('[data-testid="listing-region"] .listing-state--loading').attributes('role')).toBe('status')

    state.schemaLoading = false
    state.listError = true
    const failure = await mountList()
    expect(failure.get('[data-testid="listing-region"] .listing-state--error').attributes('role')).toBe('alert')
  })

  it('truncates many pages while preserving labels, current-page semantics, order, and target classes', async () => {
    state.rows = [{ type: 'arbitrary_record', id: 'row-1', attributes: { field_1: 'One' } }]
    state.total = 1000
    const wrapper = await mountList()
    const pagination = wrapper.get('nav[aria-label]')
    const pageButtons = pagination.findAll('button[data-page]')

    expect(pageButtons.length).toBeGreaterThanOrEqual(3)
    expect(pageButtons.length).toBeLessThanOrEqual(5)
    expect(pagination.findAll('[aria-hidden="true"].pagination-ellipsis').length).toBeGreaterThan(0)
    expect(pagination.get('[aria-current="page"]').attributes('aria-label')).toBeTruthy()
    expect(pagination.findAll('.pagination-control').every(control => control.classes().includes('touch-target'))).toBe(true)
    expect(pagination.get('[data-pagination="previous"]').attributes('aria-label')).toBe('previous')
    expect(pagination.get('[data-pagination="next"]').attributes('aria-label')).toBe('next')
  })

  it('marks row actions and sortable headers as named ordinary-size targets', async () => {
    state.rows = [{ type: 'arbitrary_record', id: 'row-1', attributes: { field_1: 'One' } }]
    state.total = 1
    const wrapper = await mountList()

    expect(wrapper.findAll('th .sortable-control').every(control => control.classes().includes('touch-target'))).toBe(true)
    expect(wrapper.findAll('.actions .btn').every(control => control.classes().includes('touch-target'))).toBe(true)
    expect(wrapper.get('.actions').attributes('role')).toBe('group')
    expect(wrapper.get('.actions').attributes('aria-label')).toContain('One')
  })
})

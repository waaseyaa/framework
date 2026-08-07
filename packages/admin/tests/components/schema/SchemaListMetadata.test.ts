import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'

const { ref } = require('vue') as typeof import('vue')
const { listMock, schemaRef } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    listMock: vi.fn(),
    schemaRef: ref<Record<string, unknown>>({}),
  }
})

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({
    hasCapability: () => true,
    getEntity: () => ({ reference: { labelField: 'title' } }),
  }),
}))
vi.mock('~/composables/useAdminConfig', () => ({ useAdminConfig: () => ({ enableRealtime: false }) }))
vi.mock('~/composables/useRealtime', () => ({
  useRealtime: () => ({ messages: ref([]), connected: ref(false), error: ref(null), connect: vi.fn(), reconnect: vi.fn() }),
}))
vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key, entityLabel: (_type: string, fallback: string) => fallback }),
}))
vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({
    schema: schemaRef,
    loading: ref(false),
    fetch: vi.fn().mockResolvedValue(undefined),
    sortedProperties: () => Object.entries((schemaRef.value.properties ?? {}) as Record<string, unknown>),
  }),
}))
vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({ list: listMock, remove: vi.fn() }),
}))

const NuxtLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }

async function mountList() {
  const { default: SchemaList } = await import('~/components/schema/SchemaList.vue')
  const wrapper = await mountSuspended(SchemaList, {
    props: { entityType: 'article' },
    global: { stubs: { NuxtLink: NuxtLinkStub } },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  window.history.replaceState({}, '', '/')
  listMock.mockReset()
  listMock.mockResolvedValue({
    data: [{
      type: 'article',
      id: '1',
      attributes: { kind: 'news', title: 'Hello', changed: '2026-07-16T10:00:00Z' },
      capabilities: { view: true, edit: false, delete: true },
    }],
    meta: { total: 60, offset: 0, limit: 25 },
  })
  schemaRef.value = {
    title: 'Articles',
    properties: {
      kind: { type: 'string' },
      title: { type: 'string' },
      changed: { type: 'string', format: 'date-time' },
    },
    'x-list': {
      columns: [
        { field: 'kind', label: 'Kind', sortable: false, formatter: 'enum', valueLabels: { news: '<News>' } },
        { field: 'title', label: 'Title', sortable: true, formatter: 'text' },
        { field: 'changed', label: 'Changed', sortable: true, formatter: 'datetime' },
      ],
      search: { field: 'title', operator: 'STARTS_WITH', label: 'Search titles', description: 'Beginning of title' },
      filters: [{ field: 'kind', operator: 'EQUALS', label: 'Content type', options: [{ value: 'news', label: '<News>' }] }],
      sorts: [
        { field: 'title', direction: 'ASC', label: 'Title (A–Z)' },
        { field: 'changed', direction: 'DESC', label: 'Recently changed' },
      ],
      defaultSort: { field: 'changed', direction: 'DESC' },
    },
  }
})

describe('SchemaList x-list', () => {
  it('renders inert declared controls, formatters, columns, and per-row actions', async () => {
    const wrapper = await mountList()

    expect(wrapper.get('[data-testid="list-search"]').attributes('aria-describedby')).toBeTruthy()
    expect(wrapper.get('[data-testid="list-filter-kind"]').text()).toContain('<News>')
    expect(wrapper.find('[data-testid="list-filter-kind"] img').exists()).toBe(false)
    expect(wrapper.get('[data-testid="list-sort"]').text()).toContain('Recently changed')
    expect(wrapper.find('[data-anchor="list-field:article:kind"] button').exists()).toBe(false)
    expect(wrapper.text()).toContain('<News>')
    expect(wrapper.find('[data-anchor="action:article:edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-anchor="action:article:delete"]').exists()).toBe(true)
    expect(listMock).toHaveBeenCalledTimes(1)
    expect(listMock).toHaveBeenLastCalledWith('article', expect.objectContaining({ sort: '-changed' }))
  })

  it('gives an empty declared label a stable visual and accessible identity', async () => {
    listMock.mockResolvedValueOnce({
      data: [{
        type: 'article',
        id: 'draft-17',
        attributes: { kind: 'news', title: '', changed: '2026-07-16T10:00:00Z' },
        capabilities: { view: true, edit: true, delete: true },
      }],
      meta: { total: 1, offset: 0, limit: 25 },
    })

    const wrapper = await mountList()
    const row = wrapper.get('tbody tr[data-row-id="draft-17"]')
    expect(row.findAll('td[data-label]')[1]?.text()).toBe('untitled')
    expect(row.attributes('aria-label')).toBe('untitled (draft-17)')
    expect(row.get('[data-anchor="action:article:delete"]').attributes('aria-label')).toBe('delete: untitled (draft-17)')
  })

  it('submits one search request and resets pagination', async () => {
    const wrapper = await mountList()
    await wrapper.get('[data-page="2"]').trigger('click')
    await flushPromises()
    expect(listMock).toHaveBeenCalledTimes(2)

    await wrapper.get('[data-testid="list-search"]').setValue('Hel')
    await wrapper.get('[data-testid="list-controls"]').trigger('submit')
    await flushPromises()

    expect(listMock).toHaveBeenCalledTimes(3)
    expect(listMock).toHaveBeenLastCalledWith('article', expect.objectContaining({
      page: { offset: 0, limit: 25 },
      filter: expect.objectContaining({ title: { operator: 'STARTS_WITH', value: 'Hel' } }),
    }))
  })

  it('ignores an out-of-order stale response', async () => {
    let resolveFirst!: (value: unknown) => void
    let resolveSecond!: (value: unknown) => void
    listMock
      .mockReset()
      .mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
      .mockImplementationOnce(() => new Promise(resolve => { resolveSecond = resolve }))

    const mounting = mountList()
    await Promise.resolve()
    const wrapper = await mounting
    await wrapper.get('[data-testid="list-search"]').setValue('New')
    void wrapper.get('[data-testid="list-controls"]').trigger('submit')
    resolveSecond({ data: [{ type: 'article', id: '2', attributes: { title: 'New result' }, capabilities: { view: true } }], meta: { total: 1 } })
    await flushPromises()
    resolveFirst({ data: [{ type: 'article', id: '1', attributes: { title: 'Stale result' }, capabilities: { view: true } }], meta: { total: 1 } })
    await flushPromises()

    expect(wrapper.text()).toContain('New result')
    expect(wrapper.text()).not.toContain('Stale result')
  })
})

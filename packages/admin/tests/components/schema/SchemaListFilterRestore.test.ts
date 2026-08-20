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

async function mountAt(query: string) {
  window.history.replaceState({}, '', query)
  const { default: SchemaList } = await import('~/components/schema/SchemaList.vue')
  const wrapper = await mountSuspended(SchemaList, {
    props: { entityType: 'article' },
    global: { stubs: { NuxtLink: NuxtLinkStub } },
  })
  await flushPromises()
  return wrapper
}

/** The filter map of the most recent executed list request. */
function executedFilter(): Record<string, { operator: string; value: string }> {
  const call = listMock.mock.calls.at(-1)
  return (call?.[1]?.filter ?? {}) as Record<string, { operator: string; value: string }>
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
    meta: { total: 1, offset: 0, limit: 25 },
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
        { field: 'kind', label: 'Kind', sortable: false, formatter: 'text' },
        { field: 'title', label: 'Title', sortable: true, formatter: 'text' },
      ],
      search: { field: 'title', operator: 'STARTS_WITH', label: 'Search titles' },
      filters: [{ field: 'kind', operator: 'EQUALS', label: 'Content type', options: [{ value: 'news', label: 'News' }] }],
      sorts: [{ field: 'title', direction: 'ASC', label: 'Title (A–Z)' }],
    },
  }
})

describe('SchemaList serialized filter restoration', () => {
  it('restores a filter when the URL operator matches the declared one', async () => {
    const wrapper = await mountAt('/?filter%5Bkind%5D%5Boperator%5D=EQUALS&filter%5Bkind%5D%5Bvalue%5D=news')

    expect(executedFilter().kind).toEqual({ operator: 'EQUALS', value: 'news' })
    expect((wrapper.get('[data-testid="list-filter-kind"]').element as HTMLSelectElement).value).toBe('news')
  })

  it('restores a search value when the URL operator matches the declared one', async () => {
    const wrapper = await mountAt('/?filter%5Btitle%5D%5Boperator%5D=STARTS_WITH&filter%5Btitle%5D%5Bvalue%5D=Hel')

    expect(executedFilter().title).toEqual({ operator: 'STARTS_WITH', value: 'Hel' })
    expect((wrapper.get('[data-testid="list-search"]').element as HTMLInputElement).value).toBe('Hel')
  })

  it('ignores a value whose operator member is missing', async () => {
    await mountAt('/?filter%5Bkind%5D%5Bvalue%5D=news')

    expect(executedFilter().kind).toBeUndefined()
  })

  it('ignores an operator whose value member is missing', async () => {
    await mountAt('/?filter%5Bkind%5D%5Boperator%5D=EQUALS')

    expect(executedFilter().kind).toBeUndefined()
  })

  it('ignores a pair whose operator disagrees with the declaration', async () => {
    await mountAt('/?filter%5Bkind%5D%5Boperator%5D=CONTAINS&filter%5Bkind%5D%5Bvalue%5D=news')

    expect(executedFilter().kind).toBeUndefined()
  })

  it('ignores a mismatched search operator, keeping the search control empty', async () => {
    const wrapper = await mountAt('/?filter%5Btitle%5D%5Boperator%5D=EQUALS&filter%5Btitle%5D%5Bvalue%5D=Hel')

    expect(executedFilter().title).toBeUndefined()
    expect((wrapper.get('[data-testid="list-search"]').element as HTMLInputElement).value).toBe('')
  })

  it('ignores a pair naming an operator the surface does not define', async () => {
    await mountAt('/?filter%5Bkind%5D%5Boperator%5D=NOT_AN_OPERATOR&filter%5Bkind%5D%5Bvalue%5D=news')

    expect(executedFilter().kind).toBeUndefined()
  })

  it('never consults a field the metadata does not declare', async () => {
    await mountAt('/?filter%5Bsecret%5D%5Boperator%5D=EQUALS&filter%5Bsecret%5D%5Bvalue%5D=1')

    expect(executedFilter().secret).toBeUndefined()
    expect(Object.keys(executedFilter())).toHaveLength(0)
  })

  it('executes the declared operator, never one supplied by the URL', async () => {
    await mountAt('/?filter%5Bkind%5D%5Boperator%5D=EQUALS&filter%5Bkind%5D%5Bvalue%5D=news')

    expect(executedFilter().kind?.operator).toBe('EQUALS')
    expect(listMock).toHaveBeenCalledTimes(1)
  })
})

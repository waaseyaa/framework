import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'

const { ref } = require('vue') as typeof import('vue')
const { listMock } = vi.hoisted(() => ({ listMock: vi.fn() }))

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({ hasCapability: () => true }),
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
    schema: ref({
      title: 'Content',
      'x-bundle-key': 'type',
      properties: {
        title: { type: 'string', 'x-widget': 'text', 'x-label': 'Title' },
        type: {
          type: 'string',
          'x-widget': 'select',
          'x-label': 'Bundle',
          enum: ['page', 'post'],
        },
        created: { type: 'string', format: 'date-time', 'x-widget': 'datetime', 'x-label': 'Authored on' },
      },
    }),
    loading: ref(false),
    fetch: vi.fn().mockResolvedValue(undefined),
    sortedProperties: () => [
      ['title', { type: 'string', 'x-widget': 'text', 'x-label': 'Title' }],
      ['type', { type: 'string', 'x-widget': 'select', 'x-label': 'Bundle' }],
      ['created', { type: 'string', format: 'date-time', 'x-widget': 'datetime', 'x-label': 'Authored on' }],
    ],
  }),
}))
vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({ list: listMock, remove: vi.fn() }),
}))

const NuxtLinkStub = { props: ['to'], template: '<a :href="to"><slot /></a>' }

function row(id: string, title: string, type: 'page' | 'post', created: string) {
  return { type: 'node', id, attributes: { title, type, created, wp_status: type === 'post' ? 'publish' : 'page' } }
}

const nodes = [
  row('f3c4de8b-df3d-4f30-9d97-cfc2a1112ad0', 'Fresh browser post', 'post', '2026-07-22T15:00:00Z'),
  ...Array.from({ length: 24 }, (_, index) => row(`10000000-0000-4000-8000-${String(index).padStart(12, '0')}`, `Page ${index}`, 'page', `2026-07-${String(21 - (index % 20)).padStart(2, '0')}T12:00:00Z`)),
  ...Array.from({ length: 10 }, (_, index) => row(`20000000-0000-4000-8000-${String(index).padStart(12, '0')}`, `Post ${index}`, 'post', `2026-06-${String(20 - index).padStart(2, '0')}T12:00:00Z`)),
]

beforeEach(() => {
  listMock.mockReset()
  listMock.mockImplementation(async (_type: string, query: any) => {
    let result = [...nodes]
    const bundle = query?.filter?.type
    if (bundle?.operator === 'EQUALS') result = result.filter(node => node.attributes.type === bundle.value)
    if (query?.sort === '-created') result.sort((a, b) => b.attributes.created.localeCompare(a.attributes.created))
    const offset = query?.page?.offset ?? 0
    const limit = query?.page?.limit ?? 25
    return { data: result.slice(offset, offset + limit), meta: { total: result.length, offset, limit } }
  })
})

describe('SchemaList node query state', () => {
  it('keeps newly created content on page 1, pages to different rows, and filters the total', async () => {
    const { default: SchemaList } = await import('~/components/schema/SchemaList.vue')
    const wrapper = await mountSuspended(SchemaList, {
      props: { entityType: 'node' },
      global: { stubs: { NuxtLink: NuxtLinkStub, teleport: true } },
    })
    await flushPromises()

    expect(listMock).toHaveBeenLastCalledWith('node', expect.objectContaining({ sort: '-created' }))
    expect(wrapper.text()).toContain('Fresh browser post')
    const pageOneIds = wrapper.findAll('tbody tr[data-row-id]').map(item => item.attributes('data-row-id'))

    await wrapper.get('[data-page="2"]').trigger('click')
    await flushPromises()
    await vi.waitFor(() => expect(listMock).toHaveBeenLastCalledWith('node', expect.objectContaining({
      page: { offset: 25, limit: 25 },
    })))
    const pageTwoIds = wrapper.findAll('tbody tr[data-row-id]').map(item => item.attributes('data-row-id'))
    expect(pageTwoIds).not.toEqual(pageOneIds)
    expect(listMock).toHaveBeenLastCalledWith('node', expect.objectContaining({
      page: { offset: 25, limit: 25 },
      sort: '-created',
    }))

    await wrapper.get('[data-testid="bundle-filter"]').setValue('post')
    await flushPromises()
    expect(listMock).toHaveBeenLastCalledWith('node', expect.objectContaining({
      page: { offset: 0, limit: 25 },
      sort: '-created',
      filter: { type: { operator: 'EQUALS', value: 'post' } },
    }))
    expect(wrapper.text()).toContain('showing 1–11 of 11')
  })
})

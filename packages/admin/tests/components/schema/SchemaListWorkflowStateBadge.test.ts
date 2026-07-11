// packages/admin/tests/components/schema/SchemaListWorkflowStateBadge.test.ts
//
// SchemaList renders a workflow-state pill (status-pill pattern, copied from
// SchedulerTaskRow.vue) whenever a listed entity carries attributes.workflow_state.
// Follows the mocking conventions of SchemaList.test.ts.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'

const { ref } = require('vue') as typeof import('vue')

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

async function mountList(entities: unknown[], sortedProperties: () => Array<[string, Record<string, unknown>]>) {
  vi.doMock('~/composables/useSchema', () => ({
    useSchema: () => ({
      schema: ref({ title: 'Content', properties: {} }),
      loading: ref(false),
      fetch: vi.fn().mockResolvedValue(undefined),
      sortedProperties,
    }),
  }))
  vi.doMock('~/composables/useEntity', () => ({
    useEntity: () => ({
      list: vi.fn().mockResolvedValue({ data: entities, meta: { total: entities.length } }),
      remove: vi.fn(),
    }),
  }))

  const { default: SchemaList } = await import('~/components/schema/SchemaList.vue')
  const wrapper = await mountSuspended(SchemaList, {
    props: { entityType: 'node' },
    global: { stubs: { NuxtLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.resetModules()
})

describe('SchemaList workflow_state badge', () => {
  it('renders a pill in the existing workflow_state column when the schema already lists it', async () => {
    const wrapper = await mountList(
      [{ type: 'node', id: '1', attributes: { title: 'Hello', workflow_state: 'review' } }],
      () => [
        ['title', { 'x-widget': 'text', 'x-label': 'Title' }],
        ['workflow_state', { 'x-widget': 'text', 'x-label': 'Workflow state' }],
      ],
    )

    const pill = wrapper.get('.status-pill')
    expect(pill.text()).toBe('review')
    // No synthetic extra column duplicating the schema-declared one.
    expect(wrapper.findAll('.status-pill')).toHaveLength(1)
  })

  it('adds a synthetic workflow-state pill column when the attribute is present but not in the schema columns', async () => {
    const wrapper = await mountList(
      [{ type: 'node', id: '1', attributes: { title: 'Hello', workflow_state: 'published' } }],
      () => [['title', { 'x-widget': 'text', 'x-label': 'Title' }]],
    )

    const pill = wrapper.get('.status-pill')
    expect(pill.text()).toBe('published')
  })

  it('does not render any pill or extra column for entity types without workflow_state', async () => {
    const wrapper = await mountList(
      [{ type: 'node', id: '1', attributes: { title: 'Hello' } }],
      () => [['title', { 'x-widget': 'text', 'x-label': 'Title' }]],
    )

    expect(wrapper.find('.status-pill').exists()).toBe(false)
    // Regression: the list still renders the normal columns fine.
    expect(wrapper.text()).toContain('Hello')
  })
})

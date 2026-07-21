// packages/admin/tests/components/schema/SchemaList.test.ts
//
// Acceptance test for the "silent Edit" UX fix: activating a row's Edit link
// must give immediate feedback (aria-busy + an "Opening…" label) while the
// destination editor resolves, and a repeat activation must be swallowed so a
// double-click can't start a second navigation.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'

const { ref } = require('vue') as typeof import('vue')

const sampleEntity = { type: 'node', id: '7', attributes: { title: 'Hello' } }

// Hoisted so the useEntity mock factory can reference it; lets a test make
// remove() reject to exercise the delete-error surfacing (D7).
const { removeMock } = vi.hoisted(() => ({ removeMock: vi.fn() }))

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
  // Identity translator: assertions check the i18n KEY so they don't depend on
  // copy. 'edit' → idle, 'opening' → busy.
  useLanguage: () => ({ t: (key: string) => key, entityLabel: (_t: string, fb: string) => fb }),
}))

vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({
    schema: ref({ title: 'Content', properties: {} }),
    loading: ref(false),
    fetch: vi.fn().mockResolvedValue(undefined),
    sortedProperties: () => [['title', { 'x-widget': 'text', 'x-label': 'Title' }]],
  }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({
    list: vi.fn().mockResolvedValue({ data: [sampleEntity], meta: { total: 1 } }),
    remove: removeMock,
  }),
}))

beforeEach(() => {
  removeMock.mockReset()
  removeMock.mockResolvedValue(undefined)
})

// Keep NuxtLink inert so clicking it exercises our @click handler without the
// router actually navigating (which would unmount the list mid-assertion).
const NuxtLinkStub = {
  props: ['to'],
  template: '<a :href="to"><slot /></a>',
}

async function mountList() {
  const { default: SchemaList } = await import('~/components/schema/SchemaList.vue')
  const wrapper = await mountSuspended(SchemaList, {
    props: { entityType: 'node' },
    global: { stubs: { NuxtLink: NuxtLinkStub, teleport: true } },
  })
  await flushPromises()
  return wrapper
}

describe('SchemaList Edit link busy state', () => {
  it('renders the Edit link idle before activation', async () => {
    const wrapper = await mountList()
    const link = wrapper.get('a[aria-busy]')
    expect(link.attributes('aria-busy')).toBe('false')
    expect(link.attributes('aria-disabled')).toBeUndefined()
    expect(link.text()).toContain('edit')
    expect(link.classes()).not.toContain('is-busy')
  })

  it('marks the clicked Edit link busy and disabled with an "opening" label', async () => {
    const wrapper = await mountList()
    const link = wrapper.get('a[aria-busy]')

    await link.trigger('click')

    expect(link.attributes('aria-busy')).toBe('true')
    expect(link.attributes('aria-disabled')).toBe('true')
    expect(link.classes()).toContain('is-busy')
    expect(link.text()).toContain('opening')
  })

  it('swallows a repeat activation once navigation is in flight', async () => {
    const wrapper = await mountList()
    const link = wrapper.get('a[aria-busy]')

    // First activation starts navigation (default not prevented).
    await link.trigger('click')

    // A second activation must be cancelled so no second route change starts.
    const repeat = new MouseEvent('click', { cancelable: true, bubbles: true })
    link.element.dispatchEvent(repeat)
    expect(repeat.defaultPrevented).toBe(true)
  })
})

describe('SchemaList Wayfinding anchor groundwork', () => {
  it('emits stable data-anchor IDs derived from entity type + field/operation', async () => {
    const wrapper = await mountList()

    // Container, field-identity column header, and per-operation action anchors.
    expect(wrapper.find('[data-anchor="list:node"]').exists()).toBe(true)
    expect(wrapper.find('[data-anchor="list-field:node:title"]').exists()).toBe(true)
    expect(wrapper.find('[data-anchor="action:node:edit"]').exists()).toBe(true)
    expect(wrapper.find('[data-anchor="action:node:delete"]').exists()).toBe(true)
  })
})

describe('SchemaList delete error surfacing (D7)', () => {
  it('shows a delete failure inline without blanking the table', async () => {
    removeMock.mockRejectedValueOnce({ message: 'Not found' })

    const wrapper = await mountList()
    await wrapper.get('button.btn-danger').trigger('click')
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(true)
    await wrapper.get('[data-testid="confirm-dialog-confirm"]').trigger('click')
    await flushPromises()

    // Inline delete-error notice is shown, framed as a delete failure...
    const notice = wrapper.find('.error--inline')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('error_deleting')
    // ...and the table is NOT replaced by a full-page error (the D7 symptom).
    expect(wrapper.find('.entity-table').exists()).toBe(true)
  })
})

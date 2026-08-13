import type { EntitySchema } from '~/contracts/schema'
import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { schemaRef, fetchSchemaMock, getMock, removeMock, navigateMock } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    schemaRef: ref<EntitySchema | null>(null),
    fetchSchemaMock: vi.fn(),
    getMock: vi.fn(),
    removeMock: vi.fn(),
    navigateMock: vi.fn(),
  }
})

mockNuxtImport('useRoute', () => () => ({ params: { entityType: 'node', id: '5' } }))
mockNuxtImport('navigateTo', () => navigateMock)
mockNuxtImport('useAdminConfig', () => () => ({ appName: 'Test admin' }))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({
    t: (key: string) => key,
    entityLabel: (_type: string, fallback: string) => fallback,
  }),
}))

vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({
    schema: schemaRef,
    fetch: fetchSchemaMock,
  }),
}))

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({ hasCapability: (_type: string, capability: string) => capability === 'delete' }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({ get: getMock, remove: removeMock }),
}))

const baseSchema = {
  $schema: 'https://json-schema.org/draft/2020-12/schema',
  title: 'Content',
  description: '',
  type: 'object',
  'x-entity-type': 'node',
  'x-translatable': false,
  'x-revisionable': true,
  properties: {},
} satisfies EntitySchema

async function mountPage() {
  const { default: EntityDetailPage } = await import('~/pages/[entityType]/[id].vue')
  const wrapper = await mountSuspended(EntityDetailPage, {
    global: {
      stubs: {
        WorkflowTransitionControls: { template: '<div data-testid="workflow-controls" />' },
        WorkflowTransitionHistoryTimeline: { template: '<div data-testid="workflow-history" />' },
        SchemaView: { template: '<div />' },
        SchemaForm: { template: '<div />' },
        NuxtLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
        teleport: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('entity detail workflow binding', () => {
  beforeEach(() => {
    schemaRef.value = null
    fetchSchemaMock.mockReset()
    getMock.mockReset().mockResolvedValue({ id: '5', attributes: { type: 'post' } })
    removeMock.mockReset()
    removeMock.mockResolvedValue(undefined)
    navigateMock.mockReset()
  })

  it('requests an entity-scoped schema and renders workflow UI only when bound', async () => {
    schemaRef.value = { ...baseSchema, 'x-workflow': { bound: true, id: 'editorial' } }
    const wrapper = await mountPage()

    expect(fetchSchemaMock).toHaveBeenCalledWith({ id: '5' })
    expect(wrapper.find('[data-testid="workflow-controls"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workflow-history"]').exists()).toBe(true)
  })

  it('does not render workflow UI for an unbound entity with ordinary editing behavior', async () => {
    schemaRef.value = { ...baseSchema, 'x-workflow': { bound: false, id: null } }
    const wrapper = await mountPage()

    expect(wrapper.find('[data-testid="workflow-controls"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="workflow-history"]').exists()).toBe(false)
    expect(wrapper.find('button.btn-primary').exists()).toBe(true)
  })

  it('offers the governed page builder from an eligible page detail', async () => {
    schemaRef.value = { ...baseSchema, 'x-workflow': { bound: true, id: 'editorial' } }
    getMock.mockResolvedValue({ id: '5', attributes: { type: 'page' } })
    const wrapper = await mountPage()

    expect(wrapper.get('[data-testid="detail-page-builder"]').attributes('href')).toBe('/page-builder/page/5')
  })

  it('deletes from the detail page through the standard confirmation modal', async () => {
    schemaRef.value = { ...baseSchema, 'x-workflow': { bound: false, id: null } }
    const wrapper = await mountPage()

    await wrapper.get('[data-testid="detail-delete"]').trigger('click')
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(true)
    await wrapper.get('[data-testid="confirm-dialog-confirm"]').trigger('click')
    await flushPromises()

    expect(removeMock).toHaveBeenCalledWith('node', '5')
    expect(navigateMock).toHaveBeenCalledWith('/node')
  })
})

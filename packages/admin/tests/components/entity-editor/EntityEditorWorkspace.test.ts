import type { EntitySchema } from '~/contracts/schema'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { schemaRef, fetchSchemaMock, removeMock, hasCapabilityMock } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    schemaRef: ref<EntitySchema | null>(null),
    fetchSchemaMock: vi.fn(),
    removeMock: vi.fn(),
    hasCapabilityMock: vi.fn(),
  }
})

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({
    t: (key: string) => key,
    entityLabel: (_type: string, fallback: string) => fallback,
  }),
}))

vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({ schema: schemaRef, fetch: fetchSchemaMock }),
}))

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({ hasCapability: hasCapabilityMock }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({ remove: removeMock }),
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

const revisionHistoryRequests: Array<{ entityType: string; entityId?: string }> = []

async function mountWorkspace(entityId?: string, entityType = 'node') {
  const { default: EntityEditorWorkspace } = await import('~/components/entity-editor/EntityEditorWorkspace.vue')
  const wrapper = await mountSuspended(EntityEditorWorkspace, {
    props: { entityType, entityId, initialBundle: 'community_event' },
    global: {
      stubs: {
        SchemaForm: {
          props: ['entityType', 'entityId', 'initialBundle'],
          emits: ['saved', 'error', 'ready', 'dirty', 'failure'],
          template: '<div data-testid="schema-form" :data-entity-id="entityId" :data-bundle="initialBundle"><button data-testid="save" @click="$emit(\'saved\', { id: entityId || \'new-id\' })">save</button><button data-testid="error" @click="$emit(\'error\', \'Save failed\')">error</button><button data-testid="ready" @click="$emit(\'ready\')">ready</button><button data-testid="dirty" @click="$emit(\'dirty\', true)">dirty</button><button data-testid="failure" @click="$emit(\'failure\', { kind: \'network\' })">failure</button></div>',
        },
        WorkflowTransitionControls: {
          emits: ['transitioned'],
          template: '<button data-testid="transition" @click="$emit(\'transitioned\', { transition: \'publish\', from: \'review\', to: \'published\', public_changed: true })">transition</button>',
        },
        WorkflowTransitionHistoryTimeline: { template: '<div data-testid="history" />' },
        EntityEditorEntityRevisionRecovery: {
          props: ['entityType', 'entityId', 'compact'],
          // Records the request the real panel would issue on mount, so a
          // rendered-but-unused panel cannot pass as "no request made".
          template: '<div data-testid="revision-recovery" />',
          mounted() { revisionHistoryRequests.push({ entityType: this.entityType, entityId: this.entityId }) },
        },
        CommonConfirmDialog: {
          props: ['open'],
          emits: ['cancel', 'confirm'],
          template: '<div v-if="open"><button data-testid="confirm-delete" @click="$emit(\'confirm\')">confirm</button></div>',
        },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('EntityEditorWorkspace', () => {
  beforeEach(() => {
    schemaRef.value = { ...baseSchema, 'x-workflow': { bound: false, id: null } }
    fetchSchemaMock.mockReset().mockResolvedValue(undefined)
    removeMock.mockReset().mockResolvedValue(undefined)
    hasCapabilityMock.mockReset().mockReturnValue(true)
    revisionHistoryRequests.length = 0
  })

  // A type that keeps no revisions has no history surface at all: the endpoint
  // answers 404, so the affordance must be withheld rather than offered and
  // then refused. Before this gate, menu_link and taxonomy_term produced a
  // console error and a failed response on every edit.
  it.each(['menu_link', 'taxonomy_term'])(
    'renders no history panel and issues no history request for non-revisionable %s',
    async (entityType) => {
      hasCapabilityMock.mockImplementation((_type: string, capability: string) => capability !== 'revisions')

      const wrapper = await mountWorkspace('42', entityType)

      expect(hasCapabilityMock).toHaveBeenCalledWith(entityType, 'revisions')
      expect(wrapper.find('[data-testid="revision-recovery"]').exists()).toBe(false)
      expect(revisionHistoryRequests).toEqual([])
    },
  )

  it('still renders history for a revisionable type', async () => {
    hasCapabilityMock.mockImplementation((_type: string, capability: string) => capability === 'revisions')

    const wrapper = await mountWorkspace('42', 'node')

    expect(wrapper.find('[data-testid="revision-recovery"]').exists()).toBe(true)
    expect(revisionHistoryRequests).toEqual([{ entityType: 'node', entityId: '42' }])
  })

  it('asks for no history while creating, where there is no record to have any', async () => {
    hasCapabilityMock.mockReturnValue(true)

    const wrapper = await mountWorkspace(undefined, 'node')

    expect(wrapper.find('[data-testid="revision-recovery"]').exists()).toBe(false)
    expect(revisionHistoryRequests).toEqual([])
  })

  it('opens create mode in the requested server-declared bundle and emits the saved identity', async () => {
    const wrapper = await mountWorkspace()

    expect(fetchSchemaMock).toHaveBeenCalledWith({ bundle: 'community_event' })
    expect(wrapper.get('[data-testid="schema-form"]').attributes('data-bundle')).toBe('community_event')
    await wrapper.get('[data-testid="save"]').trigger('click')
    await wrapper.get('[data-testid="error"]').trigger('click')

    expect(wrapper.emitted('saved')?.[0]?.[0]).toEqual({ id: 'new-id' })
    expect(wrapper.text()).toContain('entity_created')
    expect(wrapper.text()).toContain('Save failed')
    await wrapper.get('[data-testid="ready"]').trigger('click')
    await wrapper.get('[data-testid="dirty"]').trigger('click')
    await wrapper.get('[data-testid="failure"]').trigger('click')
    expect(wrapper.emitted('ready')).toHaveLength(1)
    expect(wrapper.emitted('dirty')?.[0]).toEqual([true])
    expect(wrapper.emitted('failure')?.[0]).toEqual([{ kind: 'network' }])
  })

  it('uses the same workflow and capability-gated deletion controls for existing content', async () => {
    schemaRef.value = { ...baseSchema, 'x-workflow': { bound: true, id: 'editorial' } }
    const wrapper = await mountWorkspace('42')

    expect(fetchSchemaMock).toHaveBeenCalledWith({ id: '42' })
    expect(wrapper.find('[data-testid="transition"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="history"]').exists()).toBe(true)
    await wrapper.get('[data-testid="transition"]').trigger('click')
    expect(wrapper.text()).toContain('workflow_transitioned')
    expect(wrapper.emitted('transitioned')?.[0]?.[0]).toEqual({
      transition: 'publish',
      from: 'review',
      to: 'published',
      public_changed: true,
    })

    await wrapper.get('.btn-danger').trigger('click')
    await wrapper.get('[data-testid="confirm-delete"]').trigger('click')
    await flushPromises()

    expect(removeMock).toHaveBeenCalledWith('node', '42')
    expect(wrapper.emitted('deleted')?.[0]).toEqual(['42'])
  })

  it('surfaces an authoritative delete refusal and hides delete without the capability', async () => {
    removeMock.mockRejectedValue({ data: { errors: [{ detail: 'Deletion denied' }] } })
    const wrapper = await mountWorkspace('42')
    await wrapper.get('.btn-danger').trigger('click')
    await wrapper.get('[data-testid="confirm-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Deletion denied')

    hasCapabilityMock.mockReturnValue(false)
    const readOnly = await mountWorkspace('43')
    expect(readOnly.find('.btn-danger').exists()).toBe(false)
  })
})

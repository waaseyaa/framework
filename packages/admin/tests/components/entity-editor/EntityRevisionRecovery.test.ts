import { flushPromises } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { getMock, runActionMock, hasCapabilityMock } = vi.hoisted(() => ({
  getMock: vi.fn(),
  runActionMock: vi.fn(),
  hasCapabilityMock: vi.fn(),
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({
    t: (key: string, values?: Record<string, string>) => `${key}${values?.revision ? `:${values.revision}` : ''}`,
  }),
}))
vi.mock('~/composables/useAdmin', () => ({ useAdmin: () => ({ hasCapability: hasCapabilityMock }) }))
vi.mock('~/composables/useEntity', () => ({ useEntity: () => ({ get: getMock, runAction: runActionMock }) }))

const history = {
  revisions: [
    { revisionId: 3, createdAt: null, author: 7, log: 'Current work', isCurrent: false, isLatest: true },
    { revisionId: 1, createdAt: null, author: 7, log: 'Original', isCurrent: true, isLatest: false },
  ],
}

async function mountRecovery() {
  const { default: Component } = await import('~/components/entity-editor/EntityRevisionRecovery.vue')
  const wrapper = await mountSuspended(Component, {
    props: { entityType: 'node', entityId: '7' },
    global: {
      stubs: {
        CommonConfirmDialog: {
          props: ['open'], emits: ['confirm', 'cancel'],
          template: '<button v-if="open" data-testid="confirm-restore" @click="$emit(\'confirm\')">confirm</button>',
        },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('EntityRevisionRecovery', () => {
  beforeEach(() => {
    getMock.mockReset().mockResolvedValue({ type: 'node', id: '7', attributes: { title: 'Working', starts: '2026-08-20T12:00:00Z' } })
    hasCapabilityMock.mockReset().mockReturnValue(true)
    runActionMock.mockReset().mockImplementation(async (_type: string, action: string) => {
      if (action === 'history') return history
      if (action === 'revision') return { revisionId: 1, entity: { type: 'node', id: '7', attributes: { title: 'Original', starts: '2026-08-19T12:00:00Z' } } }
      if (action === 'restore-revision') return { resultingRevisionId: 4 }
      return {}
    })
  })

  it('compares the field-filtered selected revision with the working copy', async () => {
    const wrapper = await mountRecovery()
    await wrapper.findAll('.timeline-entry button')[1]!.trigger('click')
    await flushPromises()

    expect(runActionMock).toHaveBeenCalledWith('node', 'revision', { id: '7', revision_id: 1 })
    expect(wrapper.get('[data-testid="revision-comparison"]').text()).toContain('Original')
    expect(wrapper.get('[data-testid="revision-comparison"]').text()).toContain('Working')
    expect(wrapper.findAll('tbody tr.changed')).toHaveLength(2)
  })

  it('restores with the observed latest revision and reloads authoritative history', async () => {
    const wrapper = await mountRecovery()
    await wrapper.findAll('.timeline-entry button')[1]!.trigger('click')
    await wrapper.get('.comparison-actions .btn-primary').trigger('click')
    await wrapper.get('[data-testid="confirm-restore"]').trigger('click')
    await flushPromises()

    expect(runActionMock).toHaveBeenCalledWith('node', 'restore-revision', {
      id: '7', revision_id: 1, expected_latest_revision_id: 3,
    })
    expect(runActionMock.mock.calls.filter(call => call[1] === 'history')).toHaveLength(2)
  })

  it('omits restore for a view-only principal', async () => {
    hasCapabilityMock.mockReturnValue(false)
    const wrapper = await mountRecovery()
    await wrapper.findAll('.timeline-entry button')[1]!.trigger('click')
    await flushPromises()
    expect(wrapper.find('.comparison-actions .btn-primary').exists()).toBe(false)
  })
})

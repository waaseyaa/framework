import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'

const { ref } = require('vue') as typeof import('vue')
const history = ref<Array<{ transition: string; from: string; to: string; uid: string; at: string }>>([])
const { fetchTransitions } = vi.hoisted(() => ({ fetchTransitions: vi.fn() }))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key }),
}))

vi.mock('~/composables/useWorkflowTransitions', () => ({
  useWorkflowTransitions: () => ({ history, fetchTransitions }),
}))

beforeEach(() => {
  history.value = []
  fetchTransitions.mockReset()
})

describe('TransitionHistoryTimeline', () => {
  it('renders canonical transition history returned by the workflow endpoint', async () => {
    fetchTransitions.mockImplementation(async () => {
      history.value = [{
        transition: 'submit_for_review',
        from: 'draft',
        to: 'review',
        uid: '9',
        at: '2026-08-13T14:00:00Z',
      }]
    })

    const { default: Timeline } = await import('~/components/workflow/TransitionHistoryTimeline.vue')
    const wrapper = await mountSuspended(Timeline, {
      props: { entityType: 'node', entityId: '7' },
    })
    await flushPromises()

    expect(fetchTransitions).toHaveBeenCalledWith('node', '7')
    expect(wrapper.get('[data-testid="transition-history"]').text()).toContain('submit_for_review')
    expect(wrapper.text()).toContain('draft')
    expect(wrapper.text()).toContain('review')
    expect(wrapper.text()).toContain('uid:9')
  })

  it('does not render a false history panel when no audit records exist', async () => {
    fetchTransitions.mockResolvedValue({ transitions: [], state: 'draft', history: [] })

    const { default: Timeline } = await import('~/components/workflow/TransitionHistoryTimeline.vue')
    const wrapper = await mountSuspended(Timeline, {
      props: { entityType: 'node', entityId: '8' },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="transition-history"]').exists()).toBe(false)
  })
})

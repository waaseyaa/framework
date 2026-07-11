// packages/admin/tests/components/workflow/TransitionControls.test.ts
//
// TransitionControls (packages/admin/app/components/workflow/TransitionControls.vue)
// fetches available workflow transitions on mount and renders one button per
// transition. Nested-dir Nuxt prefix: referenced elsewhere as
// <WorkflowTransitionControls> (same convention as
// workflow/TransitionHistoryTimeline.vue -> <WorkflowTransitionHistoryTimeline>,
// used at pages/[entityType]/[id].vue).
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'

const { ref } = require('vue') as typeof import('vue')

const publishTransition = { id: 'publish', label: 'Publish', to: 'published' }
const archiveTransition = { id: 'archive', label: 'Archive', to: 'archived' }

const transitionsRef = ref<Array<{ id: string; label: string; to: string }>>([])
const stateRef = ref<string | null>(null)

const { fetchTransitionsMock, applyTransitionMock } = vi.hoisted(() => ({
  fetchTransitionsMock: vi.fn(),
  applyTransitionMock: vi.fn(),
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key, entityLabel: (_t: string, fb: string) => fb }),
}))

vi.mock('~/composables/useWorkflowTransitions', () => ({
  useWorkflowTransitions: () => ({
    transitions: transitionsRef,
    state: stateRef,
    loading: ref(false),
    error: ref(null),
    fetchTransitions: fetchTransitionsMock,
    applyTransition: applyTransitionMock,
  }),
}))

beforeEach(() => {
  vi.resetModules()
  fetchTransitionsMock.mockReset()
  applyTransitionMock.mockReset()
  transitionsRef.value = []
  stateRef.value = null
})

async function mountControls() {
  const { default: TransitionControls } = await import('~/components/workflow/TransitionControls.vue')
  const wrapper = await mountSuspended(TransitionControls, {
    props: { entityType: 'node', entityId: '5' },
  })
  await flushPromises()
  return wrapper
}

describe('TransitionControls populated path', () => {
  it('renders one button per available transition with label and target state', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [publishTransition, archiveTransition]
      stateRef.value = 'review'
      return { transitions: transitionsRef.value, state: stateRef.value }
    })

    const wrapper = await mountControls()

    expect(fetchTransitionsMock).toHaveBeenCalledWith('node', '5')
    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(2)
    expect(buttons[0]!.text()).toContain('Publish')
    expect(buttons[0]!.text()).toContain('published')
    expect(buttons[1]!.text()).toContain('Archive')
    expect(buttons[1]!.text()).toContain('archived')
  })
})

describe('TransitionControls empty path', () => {
  it('renders nothing when there are no available transitions', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = []
      stateRef.value = null
      return { transitions: [], state: null }
    })

    const wrapper = await mountControls()

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.find('.transition-controls').exists()).toBe(false)
  })

  it('renders nothing when the transitions fetch 404s (composable resolves empty)', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      // The composable itself absorbs a 404 into an empty list — the
      // component never sees a rejected promise for a missing/unviewable entity.
      transitionsRef.value = []
      stateRef.value = null
      return { transitions: [], state: null }
    })

    const wrapper = await mountControls()

    expect(wrapper.findAll('button')).toHaveLength(0)
  })
})

describe('TransitionControls apply transition', () => {
  it('emits transitioned and re-fetches the transition list on a successful apply', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [publishTransition]
      stateRef.value = 'review'
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
    applyTransitionMock.mockResolvedValue({ transition: 'publish', from: 'review', to: 'published' })

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(applyTransitionMock).toHaveBeenCalledWith('node', '5', 'publish')
    expect(wrapper.emitted('transitioned')).toBeTruthy()
    expect(wrapper.emitted('transitioned')![0]![0]).toEqual({ transition: 'publish', from: 'review', to: 'published' })
    // One fetch on mount, one re-fetch after the successful apply.
    expect(fetchTransitionsMock).toHaveBeenCalledTimes(2)
  })

  it('disables buttons while an apply is pending', async () => {
    let resolveApply: (value: unknown) => void = () => {}
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [publishTransition]
      stateRef.value = 'review'
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
    applyTransitionMock.mockImplementation(() => new Promise((resolve) => { resolveApply = resolve }))

    const wrapper = await mountControls()
    const button = wrapper.get('button')
    await button.trigger('click')

    expect(button.attributes('disabled')).toBeDefined()

    resolveApply({ transition: 'publish', from: 'review', to: 'published' })
    await flushPromises()
  })

  it('shows an inline .error div with the JSON:API denial detail on a 403', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [publishTransition]
      stateRef.value = 'review'
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
    applyTransitionMock.mockRejectedValue({
      data: {
        errors: [{ detail: 'You do not have permission to publish this content.', meta: { reason: 'permission' } }],
      },
    })

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    const errorEl = wrapper.get('.error')
    expect(errorEl.text()).toBe('You do not have permission to publish this content.')
    // A failed apply does not re-fetch — only the mount-time fetch happened.
    expect(fetchTransitionsMock).toHaveBeenCalledTimes(1)
  })

  it('falls back to the generic i18n key when the error has no JSON:API detail', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [publishTransition]
      stateRef.value = 'review'
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
    // No `data.errors[].detail` and no `message` — forces the t(fallback) branch.
    applyTransitionMock.mockRejectedValue({})

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('.error').text()).toBe('workflow_transition_error_generic')
  })
})

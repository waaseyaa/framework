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
const fetchErrorRef = ref<string | null>(null)
const loadingRef = ref(false)
// The validator the composable holds. It is null before the first discovery and
// after the server refuses a precondition, which is what tells the component to
// re-read rather than let the operator press a dead button.
const mutationTokenRef = ref<string | null>(null)

const { fetchTransitionsMock, applyTransitionMock, adoptMutationTokenMock, forgetMutationTokenMock, runtimeAvailable } = vi.hoisted(() => ({
  fetchTransitionsMock: vi.fn(),
  applyTransitionMock: vi.fn(),
  adoptMutationTokenMock: vi.fn(),
  forgetMutationTokenMock: vi.fn(),
  runtimeAvailable: { value: true },
}))

// The shared admin-surface transport caches an entity mutation validator from
// its last read. A committed transition supersedes it, so the controls hand the
// successor over; a mount with no admin runtime must still transition.
vi.mock('~/composables/useAdminRuntime', () => ({
  requireAdminRuntime: () => {
    if (!runtimeAvailable.value) throw new Error('Admin runtime is unavailable.')
    return {
      transport: {
        adoptMutationToken: adoptMutationTokenMock,
        forgetMutationToken: forgetMutationTokenMock,
      },
    }
  },
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key, entityLabel: (_t: string, fb: string) => fb }),
}))

vi.mock('~/composables/useWorkflowTransitions', () => ({
  useWorkflowTransitions: () => ({
    transitions: transitionsRef,
    state: stateRef,
    loading: loadingRef,
    error: fetchErrorRef,
    mutationToken: mutationTokenRef,
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
  fetchErrorRef.value = null
  loadingRef.value = false
  mutationTokenRef.value = 'emt1.observed'
  adoptMutationTokenMock.mockReset()
  forgetMutationTokenMock.mockReset()
  runtimeAvailable.value = true
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
    expect(buttons[0]!.element.tagName).toBe('BUTTON')
    expect(buttons[0]!.attributes('type')).toBe('button')
    expect(buttons[0]!.attributes('disabled')).toBeUndefined()
    expect(buttons[0]!.attributes('aria-label')).toContain('Publish')
  })

  it('announces loading and exposes no transition button before discovery completes', async () => {
    let resolveFetch: (() => void) | undefined
    loadingRef.value = true
    fetchTransitionsMock.mockImplementation(() => new Promise((resolve) => {
      resolveFetch = () => {
        loadingRef.value = false
        transitionsRef.value = [publishTransition]
        resolve({ transitions: transitionsRef.value, state: 'review' })
      }
    }))

    const { default: TransitionControls } = await import('~/components/workflow/TransitionControls.vue')
    const wrapper = await mountSuspended(TransitionControls, {
      props: { entityType: 'node', entityId: '5' },
    })

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.get('[role="status"]').attributes('aria-live')).toBe('polite')

    resolveFetch?.()
    await flushPromises()
    expect(wrapper.findAll('button')).toHaveLength(1)
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

  it('announces a bound state with no currently available transitions', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = []
      stateRef.value = 'review'
      return { transitions: [], state: 'review' }
    })

    const wrapper = await mountControls()

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.get('[data-testid="transition-empty"]').attributes('role')).toBe('status')
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

describe('TransitionControls fetch error path', () => {
  it('renders the fetch error when the transitions GET fails with a non-404 (e.g. 500)', async () => {
    // useWorkflowTransitions.fetchTransitions() sets `error` on any non-404
    // GET failure and leaves `transitions` empty — before the fix, that
    // error was never rendered, indistinguishable from "no transitions".
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = []
      stateRef.value = null
      fetchErrorRef.value = 'Failed to load workflow transitions.'
      return { transitions: [], state: null }
    })

    const wrapper = await mountControls()

    expect(wrapper.findAll('button')).toHaveLength(0)
    const errorEl = wrapper.get('[data-testid="transition-fetch-error"]')
    expect(errorEl.text()).toBe('Failed to load workflow transitions.')
  })

  it('still renders nothing when the transitions fetch 404s (composable absorbs it, no error)', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = []
      stateRef.value = null
      fetchErrorRef.value = null
      return { transitions: [], state: null }
    })

    const wrapper = await mountControls()

    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.find('[data-testid="transition-fetch-error"]').exists()).toBe(false)
    expect(wrapper.find('.transition-controls').exists()).toBe(false)
  })
})

describe('TransitionControls validator handover', () => {
  function readyToApply(result: { transition: string; from: string; to: string; public_changed: boolean }) {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [{ id: result.transition, label: 'Advance', to: result.to }]
      stateRef.value = result.from
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
  }

  const result = { transition: 'publish', from: 'review', to: 'published', public_changed: true }

  it('hands the successor to the surface transport so the next write is not refused as stale', async () => {
    readyToApply(result)
    applyTransitionMock.mockImplementation(async () => {
      mutationTokenRef.value = 'emt1.after-transition'
      return result
    })

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(adoptMutationTokenMock).toHaveBeenCalledWith('node', '5', 'emt1.after-transition')
    expect(forgetMutationTokenMock).not.toHaveBeenCalled()
  })

  it('drops the cached validator when the transition issued no successor', async () => {
    readyToApply(result)
    applyTransitionMock.mockImplementation(async () => {
      mutationTokenRef.value = null
      return result
    })

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(forgetMutationTokenMock).toHaveBeenCalledWith('node', '5')
    expect(adoptMutationTokenMock).not.toHaveBeenCalled()
  })

  it('never touches the cached validator when the transition failed', async () => {
    readyToApply(result)
    applyTransitionMock.mockRejectedValue({ data: { errors: [{ detail: 'Denied.' }] } })

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(adoptMutationTokenMock).not.toHaveBeenCalled()
    expect(forgetMutationTokenMock).not.toHaveBeenCalled()
  })

  it('still emits a committed transition when no admin runtime is mounted', async () => {
    runtimeAvailable.value = false
    readyToApply(result)
    applyTransitionMock.mockResolvedValue(result)

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('transitioned')).toBeTruthy()
    expect(wrapper.emitted('transitioned')![0]![0]).toEqual(result)
  })
})

describe('TransitionControls mutation precondition', () => {
  it('re-reads the transitions when the server refused the mutation precondition', async () => {
    const available = { id: 'publish', label: 'Publish', to: 'published' }
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [available]
      stateRef.value = 'review'
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
    // A refused precondition leaves the composable holding no validator.
    applyTransitionMock.mockImplementation(async () => {
      mutationTokenRef.value = null
      throw { data: { errors: [{ detail: 'The resource changed after the supplied mutation precondition was observed.' }] } }
    })

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="transition-error"]').text())
      .toContain('The resource changed after the supplied mutation precondition was observed.')
    // One read on mount, one re-read after the refusal, and no second POST.
    expect(fetchTransitionsMock).toHaveBeenCalledTimes(2)
    expect(applyTransitionMock).toHaveBeenCalledTimes(1)
  })

  it('does not re-read when the failure left the held validator intact', async () => {
    const available = { id: 'publish', label: 'Publish', to: 'published' }
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [available]
      stateRef.value = 'review'
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
    applyTransitionMock.mockRejectedValue({ data: { errors: [{ detail: 'You may not publish this content.' }] } })

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="transition-error"]').text()).toContain('You may not publish this content.')
    expect(fetchTransitionsMock).toHaveBeenCalledTimes(1)
    expect(applyTransitionMock).toHaveBeenCalledTimes(1)
  })
})

describe('TransitionControls apply transition', () => {
  it.each([
    ['review', { transition: 'submit_for_review', from: 'draft', to: 'review', public_changed: false }],
    ['publication', { transition: 'publish', from: 'review', to: 'published', public_changed: true }],
    ['schedule', { transition: 'schedule', from: 'review', to: 'scheduled', public_changed: false }],
  ])('emits the authoritative %s result and re-fetches after a successful apply', async (_outcome, result) => {
    const available = { id: result.transition, label: 'Advance', to: result.to }
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [available]
      stateRef.value = result.from
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
    applyTransitionMock.mockResolvedValue(result)

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(applyTransitionMock).toHaveBeenCalledWith('node', '5', result.transition)
    expect(wrapper.emitted('transitioned')).toBeTruthy()
    expect(wrapper.emitted('transitioned')![0]![0]).toEqual(result)
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

    resolveApply({ transition: 'publish', from: 'review', to: 'published', public_changed: true })
    await flushPromises()
  })

  it('guards against duplicate submission before the disabled state renders', async () => {
    let resolveApply: (value: unknown) => void = () => {}
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [publishTransition]
      return { transitions: transitionsRef.value, state: 'review' }
    })
    applyTransitionMock.mockImplementation(() => new Promise((resolve) => { resolveApply = resolve }))

    const wrapper = await mountControls()
    const vm = wrapper.vm as unknown as { apply: (id: string) => Promise<void> }
    const first = vm.apply('publish')
    const second = vm.apply('publish')

    expect(applyTransitionMock).toHaveBeenCalledTimes(1)
    resolveApply({ transition: 'publish', from: 'review', to: 'published', public_changed: true })
    await Promise.all([first, second])
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
    expect(errorEl.attributes('role')).toBe('alert')
    expect(errorEl.attributes('aria-live')).toBe('assertive')
    // A failed apply does not re-fetch — only the mount-time fetch happened.
    expect(fetchTransitionsMock).toHaveBeenCalledTimes(1)
    expect(wrapper.emitted('transitioned')).toBeUndefined()
  })

  it('does not emit a successful state when an optimistic conflict refuses the transition', async () => {
    fetchTransitionsMock.mockImplementation(async () => {
      transitionsRef.value = [publishTransition]
      stateRef.value = 'review'
      return { transitions: transitionsRef.value, state: stateRef.value }
    })
    applyTransitionMock.mockRejectedValue({
      statusCode: 409,
      data: { errors: [{ detail: 'The entity changed before this transition could be applied.' }] },
    })

    const wrapper = await mountControls()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('.error').text()).toContain('changed')
    expect(wrapper.emitted('transitioned')).toBeUndefined()
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

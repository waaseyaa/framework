// Acceptance for the Wayfinding overlay (Phase 3, FR-012/LD-6): renders the active
// beacon as an aria-live status region, is keyboard-navigable (arrows move, Escape
// dismisses), dismissable, and spotlights the declared data-anchor element.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import * as beaconsModule from '~/composables/useBeacons'

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key }),
}))

vi.mock('~/composables/useBeacons', () => {
  const { ref, computed } = require('vue') as typeof import('vue')
  const trail = ref<Array<{ anchorId: string, content: string, order: number }>>([])
  const activeIndex = ref(0)
  const dismissedFlag = ref(false)
  const active = computed(() => trail.value[activeIndex.value] ?? null)
  const visible = computed(() => !dismissedFlag.value && active.value !== null)
  const hasPrev = computed(() => activeIndex.value > 0)
  const hasNext = computed(() => activeIndex.value < trail.value.length - 1)
  const next = vi.fn(() => { if (activeIndex.value < trail.value.length - 1) activeIndex.value += 1 })
  const prev = vi.fn(() => { if (activeIndex.value > 0) activeIndex.value -= 1 })
  const dismiss = vi.fn(() => { dismissedFlag.value = true })
  const api = { trail, active, activeIndex, visible, hasPrev, hasNext, connected: ref(true), next, prev, dismiss }
  return { useBeacons: () => api, __ctrl: { trail, activeIndex, dismissedFlag, next, prev, dismiss } }
})

const ctrl = (beaconsModule as unknown as {
  __ctrl: {
    trail: { value: Array<{ anchorId: string, content: string, order: number }> }
    activeIndex: { value: number }
    dismissedFlag: { value: boolean }
    next: ReturnType<typeof vi.fn>
    prev: ReturnType<typeof vi.fn>
    dismiss: ReturnType<typeof vi.fn>
  }
}).__ctrl

async function mountOverlay() {
  const { default: WayfindingOverlay } = await import('~/components/wayfinding/WayfindingOverlay.vue')
  const wrapper = await mountSuspended(WayfindingOverlay)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  ctrl.trail.value = []
  ctrl.activeIndex.value = 0
  ctrl.dismissedFlag.value = false
  ctrl.next.mockClear()
  ctrl.prev.mockClear()
  ctrl.dismiss.mockClear()
  document.body.innerHTML = ''
})

describe('WayfindingOverlay', () => {
  it('renders nothing when there is no active beacon', async () => {
    const wrapper = await mountOverlay()
    expect(wrapper.find('.wf-beacon').exists()).toBe(false)
  })

  it('renders the active beacon as an aria-live status region', async () => {
    ctrl.trail.value = [{ anchorId: 'field:node:title', content: 'Edit the title', order: 0 }]
    const wrapper = await mountOverlay()

    const card = wrapper.find('.wf-beacon')
    expect(card.exists()).toBe(true)
    expect(card.attributes('role')).toBe('status')
    expect(card.attributes('aria-live')).toBe('polite')
    expect(card.text()).toContain('Edit the title')
    expect(card.attributes('data-anchor-target')).toBe('field:node:title')
  })

  it('is keyboard navigable: arrows move, Escape dismisses', async () => {
    ctrl.trail.value = [
      { anchorId: 'a', content: 'one', order: 0 },
      { anchorId: 'b', content: 'two', order: 1 },
    ]
    await mountOverlay()

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
    expect(ctrl.next).toHaveBeenCalled()

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }))
    expect(ctrl.prev).toHaveBeenCalled()

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    expect(ctrl.dismiss).toHaveBeenCalled()
  })

  it('the dismiss button hides the overlay', async () => {
    ctrl.trail.value = [{ anchorId: 'a', content: 'one', order: 0 }]
    const wrapper = await mountOverlay()
    expect(wrapper.find('.wf-beacon').exists()).toBe(true)

    await wrapper.find('.wf-beacon__dismiss').trigger('click')
    await flushPromises()

    expect(ctrl.dismiss).toHaveBeenCalled()
    expect(wrapper.find('.wf-beacon').exists()).toBe(false)
  })

  it('spotlights the anchored element with a highlight class', async () => {
    const target = document.createElement('button')
    target.setAttribute('data-anchor', 'action:node:submit')
    target.textContent = 'Save'
    document.body.appendChild(target)

    ctrl.trail.value = [{ anchorId: 'action:node:submit', content: 'Now save', order: 0 }]
    await mountOverlay()
    await flushPromises()

    expect(target.classList.contains('wf-anchored')).toBe(true)
  })
})

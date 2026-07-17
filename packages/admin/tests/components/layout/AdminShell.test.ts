import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import AdminShell from '~/components/layout/AdminShell.vue'
import { useLanguage } from '~/composables/useLanguage'
import { nextTick } from 'vue'

describe('AdminShell locale switcher', () => {
  beforeEach(() => {
    const { setLocale } = useLanguage()
    setLocale('en')
  })

  it('switches translated UI labels when locale changes', async () => {
    const wrapper = await mountSuspended(AdminShell, {
      slots: {
        default: '<div>Body</div>',
      },
    })

    const select = wrapper.find('select.topbar-locale-select')
    expect(select.exists()).toBe(true)
    expect(select.attributes('aria-label')).toBe('Language')
    expect(wrapper.find('button.topbar-toggle').attributes('aria-label')).toBe('Toggle menu')

    await select.setValue('fr')

    expect(select.attributes('aria-label')).toBe('Langue')
    expect(wrapper.find('button.topbar-toggle').attributes('aria-label')).toBe('Basculer le menu')
  })

  it('displays tenant name in the topbar brand', async () => {
    const wrapper = await mountSuspended(AdminShell, {
      slots: {
        default: '<div>Body</div>',
      },
    })

    const brand = wrapper.find('.topbar-brand')
    // The bootstrap mock provides tenant name 'Waaseyaa'
    expect(brand.text()).toBe('Waaseyaa')
    expect(brand.classes()).toContain('touch-target')
  })
})

describe('AdminShell mobile off-canvas navigation', () => {
  let mediaListener: ((event: MediaQueryListEvent) => void) | undefined

  beforeEach(() => {
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      value: vi.fn().mockImplementation(() => ({
        matches: true,
        media: '(max-width: 768px)',
        onchange: null,
        addEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => { mediaListener = listener },
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    })
    document.body.style.overflow = ''
  })

  afterEach(() => {
    document.body.style.overflow = ''
    vi.restoreAllMocks()
  })

  it('makes the closed mobile panel inert, hidden to AT, and controlled by a 44px-class toggle', async () => {
    const wrapper = await mountSuspended(AdminShell, { slots: { default: '<a href="/main">Main link</a>' } })
    await nextTick()
    const toggle = wrapper.get('.topbar-toggle')
    const sidebar = wrapper.get('#admin-sidebar')

    expect(toggle.attributes('aria-controls')).toBe('admin-sidebar')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(toggle.classes()).toContain('touch-target')
    expect(sidebar.attributes('inert')).toBeDefined()
    expect(sidebar.attributes('aria-hidden')).toBe('true')
    expect(sidebar.attributes('data-pointer-state')).toBe('disabled')
  })

  it('moves focus into the panel, traps it, closes on Escape, and restores opener focus', async () => {
    const wrapper = await mountSuspended(AdminShell, { slots: { default: '<button id="behind">Behind</button>' }, attachTo: document.body })
    await nextTick()
    const toggle = wrapper.get('.topbar-toggle')
    await toggle.trigger('click')
    await nextTick()

    const sidebar = wrapper.get('#admin-sidebar')
    const close = wrapper.get('.sidebar-close')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(sidebar.attributes('inert')).toBeUndefined()
    expect(sidebar.attributes('aria-hidden')).toBeUndefined()
    expect(document.activeElement).toBe(close.element)
    expect(wrapper.get('#main-content').attributes('inert')).toBeDefined()
    expect(document.body.style.overflow).toBe('hidden')

    await sidebar.trigger('keydown', { key: 'Escape' })
    await nextTick()
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle.element)
    expect(document.body.style.overflow).toBe('')
    wrapper.unmount()
  })

  it('closes and cleans up state when the route or breakpoint changes', async () => {
    const wrapper = await mountSuspended(AdminShell, { slots: { default: '<div>Body</div>' } })
    await wrapper.get('.topbar-toggle').trigger('click')
    expect(document.body.style.overflow).toBe('hidden')

    await navigateTo('/responsive-reset')
    await nextTick()
    expect(wrapper.get('.topbar-toggle').attributes('aria-expanded')).toBe('false')
    expect(document.body.style.overflow).toBe('')

    await wrapper.get('.topbar-toggle').trigger('click')
    mediaListener?.({ matches: false } as MediaQueryListEvent)
    await nextTick()
    expect(wrapper.get('.topbar-toggle').attributes('aria-expanded')).toBe('false')
    expect(wrapper.get('#admin-sidebar').attributes('inert')).toBeUndefined()
    expect(document.body.style.overflow).toBe('')
  })
})

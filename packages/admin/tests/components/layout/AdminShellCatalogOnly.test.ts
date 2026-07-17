import { describe, expect, it, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import AdminShell from '~/components/layout/AdminShell.vue'

const { uiRef } = vi.hoisted(() => {
  return {
    uiRef: { value: {
      headerLinks: [{ label: 'Operational report', href: '/reports' }],
      sidebarItems: [],
      navigationMode: 'catalog-only' as const,
    } },
  }
})

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({
    catalog: [],
    tenant: { id: 'default', name: 'Waaseyaa' },
    ui: uiRef.value,
  }),
}))

describe('AdminShell catalog-only navigation', () => {
  it('suppresses host-declared header navigation while retaining locale controls', async () => {
    const wrapper = await mountSuspended(AdminShell, { slots: { default: '<div>Body</div>' } })

    expect(wrapper.text()).not.toContain('Operational report')
    expect(wrapper.find('.topbar-links').exists()).toBe(false)
    expect(wrapper.find('.topbar-locale-select').exists()).toBe(true)
  })
})

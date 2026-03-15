// packages/admin/tests/components/layout/NavBuilder.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import NavBuilder from '~/components/layout/NavBuilder.vue'

describe('NavBuilder', () => {
  it('renders the dashboard link always', async () => {
    const wrapper = await mountSuspended(NavBuilder)
    expect(wrapper.text()).toContain('Dashboard')
  })

  it('renders nav section headings from catalog', async () => {
    const wrapper = await mountSuspended(NavBuilder)
    // The bootstrap mock in setup.ts provides entity types that group into sections
    const navSections = wrapper.findAll('.nav-section')
    expect(navSections.length).toBeGreaterThan(0)
  })

  it('renders entity type labels as nav links', async () => {
    const wrapper = await mountSuspended(NavBuilder)
    expect(wrapper.text()).toContain('User')
    expect(wrapper.text()).toContain('Content')
  })
})

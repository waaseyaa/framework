// packages/admin/tests/components/layout/NavBuilder.test.ts
import { describe, it, expect, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import NavBuilder from '~/components/layout/NavBuilder.vue'
import { entityTypes } from '../../fixtures/entityTypes'

describe('NavBuilder', () => {
  it('renders the dashboard link always', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ data: [] }))
    const wrapper = await mountSuspended(NavBuilder)
    await flushPromises()
    expect(wrapper.text()).toContain('Dashboard')
  })

  it('renders nav section headings after fetching entity types', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ data: entityTypes }))
    const wrapper = await mountSuspended(NavBuilder)
    await flushPromises()
    // groupEntityTypes produces a 'people' group — its labelKey renders via t()
    // The nav section text comes from t('nav_group_people') etc.
    // Since the i18n keys exist in en.json, check for at least one section heading.
    const navSections = wrapper.findAll('.nav-section')
    expect(navSections.length).toBeGreaterThan(0)
  })

  it('renders entity type labels as nav links', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({ data: entityTypes }))
    const wrapper = await mountSuspended(NavBuilder)
    await flushPromises()
    expect(wrapper.text()).toContain('User')
    expect(wrapper.text()).toContain('Content')
  })

  it('shows error message when $fetch rejects', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockRejectedValue(new Error('API down')))
    const wrapper = await mountSuspended(NavBuilder)
    await flushPromises()
    expect(wrapper.find('.nav-error').exists()).toBe(true)
  })
})

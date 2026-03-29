import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import BrandPanel from '~/components/auth/BrandPanel.vue'

describe('BrandPanel', () => {
  it('renders app name from runtime config', async () => {
    const wrapper = await mountSuspended(BrandPanel)
    expect(wrapper.text()).toContain('Waaseyaa')
  })

  it('renders tagline when provided', async () => {
    const wrapper = await mountSuspended(BrandPanel, {
      props: { tagline: 'Build. Publish. Scale.' },
    })
    expect(wrapper.text()).toContain('Build. Publish. Scale.')
  })

  it('renders logo img when logoUrl is configured', async () => {
    const wrapper = await mountSuspended(BrandPanel, {
      props: { logoUrl: '/logo.svg' },
    })
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe('/logo.svg')
  })

  it('does not render img when no logoUrl', async () => {
    const wrapper = await mountSuspended(BrandPanel)
    expect(wrapper.find('img').exists()).toBe(false)
  })
})

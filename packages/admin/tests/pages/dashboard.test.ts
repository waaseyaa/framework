import { describe, it, expect } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import Dashboard from '~/pages/index.vue'

describe('Dashboard onboarding', () => {
  it('shows onboarding prompt when no content types exist', async () => {
    registerEndpoint('/_surface/node_type', () => ({
      ok: true,
      data: {
        entities: [],
        total: 0,
        offset: 0,
        limit: 1,
      },
    }))

    const wrapper = await mountSuspended(Dashboard)
    await flushPromises()

    expect(wrapper.text()).toContain('Get started with your first content type')
    expect(wrapper.text()).toContain('Use Note (built-in)')
  })
})

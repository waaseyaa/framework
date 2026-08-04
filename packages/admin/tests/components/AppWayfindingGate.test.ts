import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { nextTick } from 'vue'
import AdminApp from '~/app.vue'
import type { AdminRuntime } from '~/contracts/runtime'

describe('admin application Wayfinding gate', () => {
  async function mountApp() {
    return mountSuspended(AdminApp, {
      global: {
        stubs: {
          NuxtLoadingIndicator: true,
          NuxtLayout: { template: '<div><slot /></div>' },
          NuxtPage: true,
          WayfindingOverlay: { template: '<div data-testid="wayfinding-overlay" />' },
        },
      },
    })
  }

  it('mounts only for an authenticated account with the exact feature flag', async () => {
    const admin = useNuxtApp().$admin as AdminRuntime
    const originalFeatures = { ...admin.features }
    const currentUser = useState<AdminRuntime['account'] | null>('waaseyaa.auth.user', () => admin.account)
    admin.features = { wayfinding: true }
    currentUser.value = admin.account
    try {
      const wrapper = await mountApp()

      expect(wrapper.find('[data-testid="wayfinding-overlay"]').exists()).toBe(true)

      currentUser.value = null
      await nextTick()
      expect(wrapper.find('[data-testid="wayfinding-overlay"]').exists()).toBe(false)
    }
    finally {
      admin.features = originalFeatures
      currentUser.value = admin.account
    }
  })

  it('does not mount for a truthy non-boolean feature value', async () => {
    const admin = useNuxtApp().$admin as AdminRuntime
    const originalFeatures = { ...admin.features }
    const currentUser = useState<AdminRuntime['account'] | null>('waaseyaa.auth.user', () => admin.account)
    admin.features = { wayfinding: 'true' as unknown as boolean }
    currentUser.value = admin.account
    try {
      const wrapper = await mountApp()

      expect(wrapper.find('[data-testid="wayfinding-overlay"]').exists()).toBe(false)
    }
    finally {
      admin.features = originalFeatures
      currentUser.value = admin.account
    }
  })
})

import { describe, it, expect, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import AdminShell from '~/components/layout/AdminShell.vue'
import { useLanguage } from '~/composables/useLanguage'

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
  })
})

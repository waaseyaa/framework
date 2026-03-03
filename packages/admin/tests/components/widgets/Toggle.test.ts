// packages/admin/tests/components/widgets/Toggle.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import Toggle from '~/components/widgets/Toggle.vue'

describe('Toggle', () => {
  it('renders as a checkbox', async () => {
    const wrapper = await mountSuspended(Toggle, {
      props: { modelValue: false, label: 'Active' },
    })
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true)
  })

  it('reflects modelValue as checked state', async () => {
    const wrapper = await mountSuspended(Toggle, {
      props: { modelValue: true, label: 'Active' },
    })
    expect((wrapper.find('input').element as HTMLInputElement).checked).toBe(true)
  })

  it('emits update:modelValue with new boolean on change', async () => {
    const wrapper = await mountSuspended(Toggle, {
      props: { modelValue: false, label: 'Active' },
    })
    await wrapper.find('input').setValue(true)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([true])
  })

  it('is disabled when disabled=true', async () => {
    const wrapper = await mountSuspended(Toggle, {
      props: { modelValue: false, label: 'Active', disabled: true },
    })
    expect(wrapper.find('input').attributes('disabled')).toBeDefined()
  })
})

// packages/admin/tests/unit/components/media/AiAccessibleToggle.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import AiAccessibleToggle from '~/components/media/AiAccessibleToggle.vue'

describe('AiAccessibleToggle', () => {
  it('renders three options: inherit, yes, no', async () => {
    const wrapper = await mountSuspended(AiAccessibleToggle, {
      props: { modelValue: 'inherit' },
    })

    const options = wrapper.findAll('option')
    expect(options.length).toBe(3)
  })

  it('defaults to inherit when modelValue is undefined', async () => {
    const wrapper = await mountSuspended(AiAccessibleToggle, {
      props: {},
    })

    const select = wrapper.find('select')
    expect((select.element as HTMLSelectElement).value).toBe('inherit')
  })

  it('reflects yes modelValue', async () => {
    const wrapper = await mountSuspended(AiAccessibleToggle, {
      props: { modelValue: 'yes' },
    })

    const select = wrapper.find('select')
    expect((select.element as HTMLSelectElement).value).toBe('yes')
  })

  it('reflects no modelValue', async () => {
    const wrapper = await mountSuspended(AiAccessibleToggle, {
      props: { modelValue: 'no' },
    })

    const select = wrapper.find('select')
    expect((select.element as HTMLSelectElement).value).toBe('no')
  })

  it('emits update:modelValue on selection change', async () => {
    const wrapper = await mountSuspended(AiAccessibleToggle, {
      props: { modelValue: 'inherit' },
    })

    await wrapper.find('select').setValue('no')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['no'])
  })

  it('emits update:modelValue when changing to yes', async () => {
    const wrapper = await mountSuspended(AiAccessibleToggle, {
      props: { modelValue: 'no' },
    })

    await wrapper.find('select').setValue('yes')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['yes'])
  })

  it('is disabled when disabled=true', async () => {
    const wrapper = await mountSuspended(AiAccessibleToggle, {
      props: { modelValue: 'inherit', disabled: true },
    })

    expect(wrapper.find('select').attributes('disabled')).toBeDefined()
  })

  it('is not disabled by default', async () => {
    const wrapper = await mountSuspended(AiAccessibleToggle, {
      props: { modelValue: 'inherit' },
    })

    expect(wrapper.find('select').attributes('disabled')).toBeUndefined()
  })
})

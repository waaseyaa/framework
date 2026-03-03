// packages/admin/tests/components/widgets/TextInput.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import TextInput from '~/components/widgets/TextInput.vue'

describe('TextInput', () => {
  it('renders the label', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'Username' },
    })
    expect(wrapper.text()).toContain('Username')
  })

  it('emits update:modelValue on user input', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'Username' },
    })
    await wrapper.find('input').setValue('alice')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['alice'])
  })

  it('renders the input as disabled when disabled=true', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'Username', disabled: true },
    })
    expect(wrapper.find('input').attributes('disabled')).toBeDefined()
  })

  it('shows required asterisk when required=true', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'Username', required: true },
    })
    expect(wrapper.text()).toContain('*')
  })

  it('sets input type to email for x-widget: email', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: {
        modelValue: '',
        label: 'Email',
        schema: { type: 'string', 'x-widget': 'email' },
      },
    })
    expect(wrapper.find('input').attributes('type')).toBe('email')
  })
})

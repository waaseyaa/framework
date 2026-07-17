import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import TextInput from '~/components/widgets/TextInput.vue'
import Select from '~/components/widgets/Select.vue'
import DateInput from '~/components/widgets/DateInput.vue'
import DateTimeInput from '~/components/widgets/DateTimeInput.vue'

describe('shared admin target contract', () => {
  it.each([
    ['text', TextInput, { modelValue: '', label: 'Title' }],
    ['select', Select, { modelValue: '', label: 'Type', schema: { type: 'string', enum: ['page'] } }],
    ['date', DateInput, { modelValue: null, label: 'Publish date' }],
    ['datetime', DateTimeInput, { modelValue: '', label: 'Starts' }],
  ])('applies the shared target utility to %s controls', async (_name, component, props) => {
    const wrapper = await mountSuspended(component, { props })
    expect(wrapper.get('.field-input').classes()).toContain('touch-target')
  })

  it('keeps disabled controls in the same target contract', async () => {
    const wrapper = await mountSuspended(TextInput, {
      props: { modelValue: '', label: 'System value', disabled: true },
    })
    const input = wrapper.get('input')
    expect(input.attributes('disabled')).toBeDefined()
    expect(input.classes()).toContain('touch-target')
  })
})

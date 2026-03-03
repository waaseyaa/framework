// packages/admin/tests/components/widgets/Select.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import Select from '~/components/widgets/Select.vue'

describe('Select', () => {
  const schema = {
    type: 'string',
    enum: ['active', 'blocked'],
    'x-enum-labels': { active: 'Active', blocked: 'Blocked' },
  }

  it('renders an option for each enum value', async () => {
    const wrapper = await mountSuspended(Select, {
      props: { modelValue: '', label: 'Status', schema },
    })
    const options = wrapper.findAll('option')
    // includes the "-- Select --" placeholder + 2 enum options
    expect(options.length).toBe(3)
    expect(options[1].text()).toBe('Active')
    expect(options[2].text()).toBe('Blocked')
  })

  it('emits update:modelValue on selection change', async () => {
    const wrapper = await mountSuspended(Select, {
      props: { modelValue: '', label: 'Status', schema },
    })
    await wrapper.find('select').setValue('blocked')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['blocked'])
  })

  it('is disabled when disabled=true', async () => {
    const wrapper = await mountSuspended(Select, {
      props: { modelValue: '', label: 'Status', schema, disabled: true },
    })
    expect(wrapper.find('select').attributes('disabled')).toBeDefined()
  })
})

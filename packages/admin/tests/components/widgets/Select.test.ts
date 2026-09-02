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

  it('reads enum values from array items for multi-value fields', async () => {
    const wrapper = await mountSuspended(Select, {
      props: {
        modelValue: '',
        label: 'Status',
        schema: {
          type: 'array',
          items: { type: 'string', enum: ['active', 'blocked'] },
          'x-enum-labels': { active: 'Active', blocked: 'Blocked' },
        },
      },
    })
    expect(wrapper.findAll('option').map(option => option.text())).toEqual([
      'Active',
      'Blocked',
    ])
    expect(wrapper.get('select').attributes('multiple')).toBeDefined()
  })

  it('emits selected array values and preserves item integer types', async () => {
    const wrapper = await mountSuspended(Select, {
      props: {
        modelValue: [1],
        label: 'Ratings',
        schema: {
          type: 'array',
          items: { type: 'integer', enum: [1, 9] },
          'x-enum-labels': { '1': 'One', '9': 'Nine' },
        },
      },
    })

    await wrapper.get('select').setValue(['1', '9'])
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([[1, 9]])
    expect(wrapper.findAll('option')[0]?.text()).toBe('One')
    expect(wrapper.findAll('option')[1]?.text()).toBe('Nine')
  })

  it('emits an empty array when all multi-value options are cleared', async () => {
    const wrapper = await mountSuspended(Select, {
      props: {
        modelValue: ['active'],
        label: 'Status',
        schema: {
          type: 'array',
          items: { type: 'string', enum: ['active', 'blocked'] },
        },
      },
    })

    await wrapper.get('select').setValue([])
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([[]])
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

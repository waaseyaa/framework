import { describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import DateInput from '~/components/widgets/DateInput.vue'

describe('DateInput', () => {
  it('renders date-only values without timezone conversion', async () => {
    const wrapper = await mountSuspended(DateInput, {
      props: {
        id: 'closing-date',
        modelValue: '2026-06-03',
        label: 'Closing date',
        descriptionId: 'closing-date-help',
        describedBy: 'closing-date-help',
      },
    })

    const input = wrapper.get('input')
    expect(input.attributes('type')).toBe('date')
    expect(input.attributes('value')).toBe('2026-06-03')
    expect(input.attributes('aria-describedby')).toBe('closing-date-help')
  })

  it('preserves unset as null and emits ISO dates verbatim', async () => {
    const wrapper = await mountSuspended(DateInput, {
      props: { id: 'publish-date', modelValue: null, label: 'Publish date' },
    })

    expect(wrapper.get('input').attributes('value')).toBe('')
    await wrapper.get('input').setValue('2026-07-15')
    expect(wrapper.emitted('update:modelValue')).toEqual([['2026-07-15']])

    await wrapper.get('input').setValue('')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null])
  })

  it('honors authoritative bounds and exposes invalid state', async () => {
    const wrapper = await mountSuspended(DateInput, {
      props: {
        id: 'expiry-date',
        modelValue: '2026-07-20',
        label: 'Expiry date',
        error: 'Expiry date must be on or before 2026-07-18.',
        errorId: 'expiry-date-error',
        describedBy: 'expiry-date-error',
        schema: { type: 'string', format: 'date', 'x-min': '2026-07-01', 'x-max': '2026-07-18' },
      },
    })

    const input = wrapper.get('input')
    expect(input.attributes('min')).toBe('2026-07-01')
    expect(input.attributes('max')).toBe('2026-07-18')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toBe('expiry-date-error')
    expect(wrapper.get('#expiry-date-error').attributes('role')).toBe('alert')
  })
})

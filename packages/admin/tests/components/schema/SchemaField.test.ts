// packages/admin/tests/components/schema/SchemaField.test.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import SchemaField from '~/components/schema/SchemaField.vue'
import type { SchemaProperty } from '~/composables/useSchema'

function makeSchema(widget: string, extra: Partial<SchemaProperty> = {}): SchemaProperty {
  return { type: 'string', 'x-widget': widget, 'x-label': 'Test Field', ...extra }
}

describe('SchemaField widget dispatch', () => {
  it('renders a text input for x-widget: text', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: { name: 'title', modelValue: '', schema: makeSchema('text') },
    })
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
  })

  it('renders a checkbox for x-widget: boolean', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: { name: 'active', modelValue: false, schema: makeSchema('boolean') },
    })
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true)
  })

  it('renders a select for x-widget: select', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: {
        name: 'status',
        modelValue: '',
        schema: makeSchema('select', { enum: ['a', 'b'] }),
      },
    })
    expect(wrapper.find('select').exists()).toBe(true)
  })

  it('falls back to text input for unknown x-widget value', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: { name: 'field', modelValue: '', schema: makeSchema('unknown_widget') },
    })
    expect(wrapper.find('input').exists()).toBe(true)
  })

  it('passes disabled=true to widget when x-access-restricted is set', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: {
        name: 'email',
        modelValue: 'test@example.com',
        schema: makeSchema('text', { readOnly: true, 'x-access-restricted': true }),
        disabled: true,
      },
    })
    expect(wrapper.find('input').attributes('disabled')).toBeDefined()
  })
})

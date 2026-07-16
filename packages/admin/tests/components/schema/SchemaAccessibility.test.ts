import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import SchemaField from '~/components/schema/SchemaField.vue'
import type { EntitySchema } from '~/composables/useSchema'

const accessibleSchema: EntitySchema = {
  $schema: 'https://json-schema.org/draft-07/schema#',
  title: 'Accessible record',
  description: 'Accessibility test schema',
  type: 'object',
  'x-entity-type': 'accessible_record',
  'x-translatable': false,
  'x-revisionable': false,
  properties: {
    title: {
      type: 'string',
      'x-widget': 'text',
      'x-label': 'Title',
      'x-description': 'Use a descriptive title.',
      'x-required': true,
    },
    summary: {
      type: 'string',
      'x-widget': 'textarea',
      'x-label': 'Summary',
    },
  },
  required: ['title'],
}

const bundledSchema: EntitySchema = {
  ...accessibleSchema,
  'x-entity-type': 'accessible_bundle',
  'x-bundle-key': 'type',
  properties: {
    type: {
      type: 'string',
      enum: ['page'],
      'x-widget': 'select',
      'x-label': 'Bundle',
      'x-required': true,
      'x-weight': -100,
    },
    title: accessibleSchema.properties.title!,
  },
  required: ['type', 'title'],
}

const collidingNamesSchema: EntitySchema = {
  ...accessibleSchema,
  'x-entity-type': 'accessible_colliding_names',
  properties: {
    foo_bar: { type: 'string', 'x-widget': 'text', 'x-label': 'Underscore name' },
    'foo-bar': { type: 'string', 'x-widget': 'text', 'x-label': 'Hyphen name' },
  },
  required: [],
}

registerEndpoint('/admin/_surface/accessible_client/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: accessibleSchema }),
})

registerEndpoint('/admin/_surface/accessible_api/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: accessibleSchema }),
})

registerEndpoint('/admin/_surface/accessible_api/action/create', {
  method: 'POST',
  handler: () => ({
    ok: false,
    error: {
      status: 422,
      title: 'Validation failed',
      detail: 'The title is already in use.',
      source: { pointer: '/attributes/title' },
    },
  }),
})

registerEndpoint('/admin/_surface/accessible_bundle/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: bundledSchema }),
})

registerEndpoint('/admin/_surface/accessible_colliding_names/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: collidingNamesSchema }),
})

const accessibleDateSchema: EntitySchema = {
  ...accessibleSchema,
  'x-entity-type': 'accessible_date',
  properties: {
    closing_date: {
      type: 'string',
      format: 'date',
      'x-widget': 'date',
      'x-label': 'Closing date',
      'x-min': '2026-07-01',
      'x-max': '2026-07-18',
    },
  },
  required: [],
}

for (const type of ['accessible_date', 'accessible_date_invalid']) {
  registerEndpoint(`/admin/_surface/${type}/action/schema`, {
    method: 'POST',
    handler: () => ({ ok: true, data: accessibleDateSchema }),
  })
}

registerEndpoint('/admin/_surface/accessible_date_invalid/1', {
  method: 'GET',
  handler: () => ({
    ok: true,
    data: { type: 'accessible_date_invalid', id: '1', attributes: { closing_date: '2026-02-30' } },
  }),
})

let duplicateSubmitCount = 0
let resolveDuplicateSubmit: (() => void) | undefined
registerEndpoint('/admin/_surface/accessible_duplicate/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: { ...accessibleSchema, required: [], properties: { summary: accessibleSchema.properties.summary! } } }),
})
registerEndpoint('/admin/_surface/accessible_duplicate/action/create', {
  method: 'POST',
  handler: async () => {
    duplicateSubmitCount++
    await new Promise<void>(resolve => { resolveDuplicateSubmit = resolve })
    return { ok: true, data: { type: 'accessible_duplicate', id: '1', attributes: {} } }
  },
})

beforeEach(() => {
  vi.resetModules()
  duplicateSubmitCount = 0
  resolveDuplicateSubmit = undefined
})

describe('SchemaField accessible structure', () => {
  it('associates a stable id, label, help, required state, and field error', async () => {
    const wrapper = await mountSuspended(SchemaField, {
      props: {
        name: 'title',
        modelValue: '',
        inputId: 'schema-node-title',
        error: 'Enter a title.',
        schema: accessibleSchema.properties.title!,
      },
    })

    const input = wrapper.get('input')
    expect(input.attributes('id')).toBe('schema-node-title')
    expect(wrapper.get('label').attributes('for')).toBe('schema-node-title')
    expect(input.attributes('required')).toBeDefined()
    expect(input.attributes('aria-required')).toBe('true')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')?.split(' ')).toEqual([
      'schema-node-title-description',
      'schema-node-title-error',
    ])
    expect(wrapper.get('#schema-node-title-description').text()).toBe('Use a descriptive title.')
    expect(wrapper.get('#schema-node-title-error').text()).toContain('Error: Enter a title.')
  })

  it('does not expose aria-invalid when valid and keeps ids unique across field instances', async () => {
    const first = await mountSuspended(SchemaField, {
      props: { name: 'title', modelValue: 'One', inputId: 'schema-node-title', schema: accessibleSchema.properties.title! },
    })
    const second = await mountSuspended(SchemaField, {
      props: { name: 'summary', modelValue: 'Two', inputId: 'schema-node-summary', schema: accessibleSchema.properties.summary! },
    })

    expect(first.get('input').attributes('aria-invalid')).toBeUndefined()
    expect(new Set([first.get('input').attributes('id'), second.get('textarea').attributes('id')]).size).toBe(2)
  })
})

describe('SchemaForm accessible validation', () => {
  it('keeps IDs unique when distinct field names have the same readable slug', async () => {
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaForm, { props: { entityType: 'accessible_colliding_names' } })
    await flushPromises()

    const inputs = wrapper.findAll('input')
    const ids = inputs.map(input => input.attributes('id'))
    expect(new Set(ids).size).toBe(ids.length)
    expect(wrapper.findAll('label').map(label => label.attributes('for')).sort()).toEqual([...ids].sort())
  })

  it('uses an assertive focused summary and associated field message for client validation', async () => {
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaForm, { props: { entityType: 'accessible_client' }, attachTo: document.body })
    await flushPromises()

    expect(wrapper.get('form').attributes('novalidate')).toBeDefined()
    const renderedIds = wrapper.findAll('[id]').map(element => element.attributes('id'))
    expect(new Set(renderedIds).size).toBe(renderedIds.length)
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const summary = wrapper.get('[data-testid="validation-summary"]')
    expect(summary.attributes('role')).toBe('alert')
    expect(summary.attributes('aria-live')).toBe('assertive')
    expect(summary.attributes('tabindex')).toBe('-1')
    expect(document.activeElement).toBe(summary.element)
    expect(summary.text()).toContain('Title')
    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('input').attributes('aria-describedby')).toContain('error')

    await wrapper.get('input').setValue('Corrected')
    expect(wrapper.find('[data-testid="validation-summary"]').exists()).toBe(false)
    expect(wrapper.get('input').attributes('aria-invalid')).toBeUndefined()
    wrapper.unmount()
  })

  it('maps a structured API pointer to the matching field', async () => {
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaForm, { props: { entityType: 'accessible_api' } })
    await flushPromises()
    await wrapper.get('input').setValue('Duplicate')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="validation-summary"]').text()).toContain('The title is already in use.')
    expect(wrapper.get('.field-error').text()).toContain('The title is already in use.')
  })

  it('clears stale validation when a bundle schema is selected', async () => {
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaForm, { props: { entityType: 'accessible_bundle' } })
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-testid="validation-summary"]').exists()).toBe(true)

    await wrapper.get('select').setValue('page')
    await flushPromises()
    expect(wrapper.find('[data-testid="validation-summary"]').exists()).toBe(false)
    expect(wrapper.get('input').attributes('aria-invalid')).toBeUndefined()
    const titleId = wrapper.get('input').attributes('id')
    expect(wrapper.find(`label[for="${titleId}"]`).exists()).toBe(true)
  })

  it('associates date syntax and authoritative bound errors without timezone coercion', async () => {
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const bounded = await mountSuspended(SchemaForm, { props: { entityType: 'accessible_date' } })
    await flushPromises()
    await bounded.get('input[type="date"]').setValue('2026-07-20')
    await bounded.get('form').trigger('submit')
    await flushPromises()

    expect(bounded.get('[data-testid="validation-summary"]').text()).toContain('on or before 2026-07-18')
    expect(bounded.get('.field-error').text()).toContain('on or before 2026-07-18')
    expect(bounded.get('input[type="date"]').attributes('aria-invalid')).toBe('true')

    const malformed = await mountSuspended(SchemaForm, {
      props: { entityType: 'accessible_date_invalid', entityId: '1' },
    })
    await flushPromises()
    await malformed.get('form').trigger('submit')
    await flushPromises()

    expect(malformed.get('[data-testid="validation-summary"]').text()).toContain('YYYY-MM-DD')
    expect(malformed.get('input[type="date"]').attributes('aria-invalid')).toBe('true')
  })

  it('blocks two submit events dispatched in the same tick', async () => {
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaForm, { props: { entityType: 'accessible_duplicate' } })
    await flushPromises()
    const form = wrapper.get('form').element
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flushPromises()

    expect(duplicateSubmitCount).toBe(1)
    resolveDuplicateSubmit?.()
    await flushPromises()
  })
})

describe('validation error normalization', () => {
  it('maps structured errors and safely falls back for unmapped, malformed, forbidden, network, and server failures', async () => {
    const { normalizeValidationFailure } = await import('~/components/schema/validationErrors')
    const fields = new Set(['title'])

    expect(normalizeValidationFailure({ detail: 'Bad title', source: { parameter: 'title' } }, fields).fieldErrors)
      .toEqual({ title: 'Bad title' })
    expect(normalizeValidationFailure({ detail: 'Other field', source: { pointer: '/attributes/missing' } }, fields).globalErrors)
      .toEqual(['Other field'])
    expect(normalizeValidationFailure({ data: { errors: [{ detail: 'Nested', source: { pointer: '/data/attributes/title' } }] } }, fields).fieldErrors)
      .toEqual({ title: 'Nested' })
    expect(normalizeValidationFailure({ status: 403, title: 'Forbidden' }, fields).globalErrors[0]).toMatch(/permission/i)
    expect(normalizeValidationFailure(new TypeError('Failed to fetch'), fields).globalErrors[0]).toMatch(/network/i)
    expect(normalizeValidationFailure({ status: 500, title: 'Internal Server Error' }, fields).globalErrors[0]).toMatch(/server/i)
    expect(normalizeValidationFailure({ data: { errors: 'invalid' } }, fields).globalErrors[0]).toMatch(/save/i)
  })
})

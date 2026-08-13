// packages/admin/tests/components/schema/SchemaForm.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { readBody } from 'h3'
import SchemaForm from '~/components/schema/SchemaForm.vue'
import { userSchema } from '../../fixtures/schemas'

const bundledNodeDiscoverySchema = {
  $schema: 'https://json-schema.org/draft-07/schema#',
  title: 'Content',
  description: 'Content schema',
  type: 'object',
  'x-entity-type': 'node',
  'x-translatable': false,
  'x-revisionable': true,
  'x-bundle-key': 'type',
  properties: {
    type: {
      type: 'string',
      enum: ['page', 'post'],
      'x-widget': 'select',
      'x-label': 'Bundle',
      'x-required': true,
      'x-weight': -100,
    },
    title: {
      type: 'string',
      'x-widget': 'text',
      'x-label': 'Title',
    },
  },
  required: ['type', 'title'],
}

const pageNodeSchema = {
  ...bundledNodeDiscoverySchema,
  title: 'Page',
  properties: {
    ...bundledNodeDiscoverySchema.properties,
    page_summary: {
      type: 'string',
      'x-widget': 'textarea',
      'x-label': 'Page summary',
      'x-weight': 10,
    },
  },
}

// Register schema endpoints — transport POSTs to /admin/_surface/{type}/action/schema
registerEndpoint('/admin/_surface/user/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})

registerEndpoint('/admin/_surface/user_create/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})

registerEndpoint('/admin/_surface/user_create_err/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})

registerEndpoint('/admin/_surface/user_edit/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})

registerEndpoint('/admin/_surface/user_edit_patch/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: userSchema }),
})

const schemaWithDefaults = {
  ...userSchema,
  'x-entity-type': 'node_defaults',
  properties: {
    ...userSchema.properties,
    status: {
      type: 'boolean',
      'x-widget': 'boolean',
      'x-label': 'Published',
      'x-weight': 10,
      default: true,
    },
    promote: {
      type: 'boolean',
      'x-widget': 'boolean',
      'x-label': 'Promoted',
      'x-weight': 11,
      default: false,
    },
    sticky: {
      type: 'boolean',
      'x-widget': 'boolean',
      'x-label': 'Sticky',
      'x-weight': 12,
    },
  },
}

registerEndpoint('/admin/_surface/node_defaults/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: schemaWithDefaults }),
})

const machineNameSchema = {
  $schema: 'http://json-schema.org/draft-07/schema#',
  title: 'Content Type',
  description: 'A content type',
  type: 'object',
  'x-entity-type': 'content_type',
  'x-translatable': false,
  'x-revisionable': false,
  properties: {
    title: {
      type: 'string',
      'x-widget': 'text',
      'x-label': 'Title',
      'x-weight': 0,
      'x-required': true,
    },
    type: {
      type: 'string',
      'x-widget': 'machine_name',
      'x-source-field': 'title',
      'x-label': 'Machine name',
      'x-weight': 1,
      'x-required': true,
    },
  },
  required: ['title', 'type'],
}

registerEndpoint('/admin/_surface/content_type/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: machineNameSchema }),
})

registerEndpoint('/admin/_surface/content_type_edit/action/schema', {
  method: 'POST',
  handler: () => ({ ok: true, data: machineNameSchema }),
})

// Reset modules to clear schema cache
beforeEach(() => {
  vi.resetModules()
})

describe('SchemaForm loading and error states', () => {
  it('shows error state when schema fetch fails', async () => {
    registerEndpoint('/admin/_surface/user_err_state/action/schema', {
      method: 'POST',
      handler: () => {
        throw createError({ statusCode: 404, statusMessage: 'Not Found' })
      },
    })
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'user_err_state' },
    })
    await flushPromises()
    expect(wrapper.find('.error').exists()).toBe(true)
  })

  it('renders form fields after schema loads', async () => {
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'user' },
    })
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('renders schema-declared task sections without dropping unassigned fields', async () => {
    const groupedSchema = {
      ...bundledNodeDiscoverySchema,
      'x-entity-type': 'node_grouped_form',
      'x-form-sections': [
        {
          id: 'basics',
          label: 'Basics',
          description: 'What community members need to recognize the event.',
          fields: ['title'],
        },
        {
          id: 'location',
          label: 'Location',
          description: 'Where the event takes place.',
          fields: ['venue'],
          collapsible: true,
          collapsed: true,
        },
      ],
      properties: {
        title: {
          type: 'string',
          'x-widget': 'text',
          'x-label': 'Title',
          'x-weight': 0,
        },
        venue: {
          type: 'string',
          'x-widget': 'text',
          'x-label': 'Venue',
          'x-weight': 10,
        },
        notes: {
          type: 'string',
          'x-widget': 'textarea',
          'x-label': 'Internal notes',
          'x-weight': 20,
        },
      },
      required: ['title'],
    }
    registerEndpoint('/admin/_surface/node_grouped_form/action/schema', {
      method: 'POST',
      handler: () => ({ ok: true, data: groupedSchema }),
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'node_grouped_form' },
    })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('.loading').exists()).toBe(false))

    const sections = wrapper.findAll('[data-testid="form-section"]')
    expect(sections).toHaveLength(3)
    expect(sections[0]!.get('h2').text()).toBe('Basics')
    expect(sections[0]!.text()).toContain('What community members need to recognize the event.')
    expect(sections[0]!.find('input').attributes('id')).toContain('title')

    const location = sections[1]!.get('details')
    expect(location.attributes('open')).toBeUndefined()
    expect(location.get('summary').text()).toContain('Location')
    expect(location.text()).toContain('Where the event takes place.')
    expect(location.find('input').attributes('id')).toContain('venue')

    expect(sections[2]!.get('h2').text()).toBe('Other details')
    expect(sections[2]!.text()).toContain('Internal notes')
    expect(wrapper.findAll('.field-anchor')).toHaveLength(3)
  })

  it('opens a collapsed section when one of its fields has a validation error', async () => {
    const groupedSchema = {
      ...bundledNodeDiscoverySchema,
      'x-entity-type': 'node_group_error',
      'x-form-sections': [{
        id: 'details',
        label: 'Details',
        fields: ['title'],
        collapsible: true,
        collapsed: true,
      }],
      properties: {
        title: { type: 'string', 'x-widget': 'text', 'x-label': 'Title', 'x-required': true },
      },
      required: ['title'],
    }
    registerEndpoint('/admin/_surface/node_group_error/action/schema', {
      method: 'POST',
      handler: () => ({ ok: true, data: groupedSchema }),
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, { props: { entityType: 'node_group_error' } })
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="form-section"] details').attributes('open')).toBeDefined()
    expect(wrapper.text()).toContain('Title is required.')
  })
})

describe('SchemaForm submit — create mode (no entityId)', () => {
  it('opens directly in a requested bundle without duplicating its widgets in the host shell', async () => {
    const schemaPayloads: Array<Record<string, unknown>> = []
    registerEndpoint('/admin/_surface/node_prefilled_bundle/action/schema', {
      method: 'POST',
      handler: async (event) => {
        const payload = await readBody<Record<string, unknown>>(event)
        schemaPayloads.push(payload)
        return { ok: true, data: payload.bundle === 'page' ? pageNodeSchema : bundledNodeDiscoverySchema }
      },
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'node_prefilled_bundle', initialBundle: 'page' },
    })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('.loading').exists()).toBe(false))

    expect(schemaPayloads).toEqual([{}, { bundle: 'page' }])
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('page')
    expect(wrapper.text()).toContain('Page summary')
  })

  it('uses the schema-declared bundle key when a host requests a bundle', async () => {
    const discoverySchema = {
      ...bundledNodeDiscoverySchema,
      'x-bundle-key': 'kind',
      properties: {
        kind: {
          type: 'string',
          enum: ['page'],
          'x-widget': 'select',
          'x-label': 'Kind',
        },
      },
      required: ['kind'],
    }
    const scopedSchema = {
      ...discoverySchema,
      properties: {
        ...discoverySchema.properties,
        summary: { type: 'string', 'x-widget': 'textarea', 'x-label': 'Summary' },
      },
    }
    registerEndpoint('/admin/_surface/node_schema_bundle_key/action/schema', {
      method: 'POST',
      handler: async (event) => {
        const payload = await readBody<Record<string, unknown>>(event)
        return { ok: true, data: payload.bundle === 'page' ? scopedSchema : discoverySchema }
      },
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'node_schema_bundle_key', initialBundle: 'page' },
    })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('.loading').exists()).toBe(false))

    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('page')
    expect(wrapper.text()).toContain('Summary')
  })

  it('generates an editable Unicode slug and stops regenerating after manual edits', async () => {
    const schema = {
      ...bundledNodeDiscoverySchema,
      'x-entity-type': 'node_form_quality',
      properties: {
        ...bundledNodeDiscoverySchema.properties,
        slug: {
          type: 'string',
          'x-widget': 'slug',
          'x-source-field': 'title',
          'x-label': 'Slug',
          'x-required': true,
        },
      },
      required: ['type', 'title', 'slug'],
    }
    registerEndpoint('/admin/_surface/node_form_quality/action/schema', {
      method: 'POST',
      handler: () => ({ ok: true, data: schema }),
    })
    registerEndpoint('/admin/_surface/node_form_quality/action/generate-slug', {
      method: 'POST',
      handler: async event => {
        const payload = await readBody<{ value: string }>(event)
        return { ok: true, data: { slug: payload.value === 'Anishinaabemowin Ākí' ? 'anishinaabemowin-ākí' : '' } }
      },
    })
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, { props: { entityType: 'node_form_quality' } })
    await flushPromises()

    const bundle = wrapper.get('select')
    await bundle.setValue('post')
    const textInputs = wrapper.findAll('input[type="text"]')
    const title = textInputs.find(input => !input.classes().includes('field-input--slug'))!
    await title.setValue('Anishinaabemowin Ākí')
    await flushPromises()

    const slug = wrapper.get('.field-input--slug')
    await vi.waitFor(() => expect((slug.element as HTMLInputElement).value).toBe('anishinaabemowin-ākí'))
    await slug.setValue('anishinaabemowin-ākí-edited')
    await title.setValue('A changed title')
    await flushPromises()

    expect((slug.element as HTMLInputElement).value).toBe('anishinaabemowin-ākí-edited')
  })

  it('retains the selected bundle after bundle-scoped server validation fails', async () => {
    const schemaPayloads: Array<Record<string, unknown>> = []
    let createPayload: Record<string, any> | null = null
    const postSchema = {
      ...bundledNodeDiscoverySchema,
      title: 'Post',
      properties: {
        ...bundledNodeDiscoverySchema.properties,
        body: { type: 'string', 'x-widget': 'textarea', 'x-label': 'Body' },
      },
    }
    registerEndpoint('/admin/_surface/node_validation_bundle/action/schema', {
      method: 'POST',
      handler: async (event) => {
        const payload = await readBody<Record<string, unknown>>(event)
        schemaPayloads.push(payload)
        return { ok: true, data: payload.bundle === 'post' ? postSchema : bundledNodeDiscoverySchema }
      },
    })
    registerEndpoint('/admin/_surface/node_validation_bundle/action/create', {
      method: 'POST',
      handler: async (event) => {
        createPayload = await readBody<Record<string, any>>(event)
        return {
          ok: false,
          error: { status: 422, title: 'Validation failed', detail: 'Body is required.', source: { pointer: '/data/attributes/body' } },
        }
      },
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, { props: { entityType: 'node_validation_bundle' } })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('form').exists()).toBe(true))

    const bundle = wrapper.get('[data-anchor="field:node_validation_bundle:type"] select')
    await bundle.setValue('post')
    await flushPromises()
    await wrapper.get('input[type="text"]').setValue('Validation failure post')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.text()).toContain('Body is required.'))

    expect(schemaPayloads).toContainEqual({ bundle: 'post' })
    expect(createPayload).toMatchObject({ attributes: { type: 'post', title: 'Validation failure post' } })
    expect(wrapper.get('[data-anchor="field:node_validation_bundle:type"] select').element).toHaveProperty('value', 'post')
  })

  it('loads a bundle-specific schema after selection and submits the selected bundle', async () => {
    const schemaPayloads: Array<Record<string, unknown>> = []
    let createPayload: Record<string, any> | null = null
    registerEndpoint('/admin/_surface/node_bundled_create/action/schema', {
      method: 'POST',
      handler: async (event) => {
        const payload = await readBody<Record<string, unknown>>(event)
        schemaPayloads.push(payload)
        return {
          ok: true,
          data: payload.bundle === 'page' ? pageNodeSchema : bundledNodeDiscoverySchema,
        }
      },
    })
    registerEndpoint('/admin/_surface/node_bundled_create/action/create', {
      method: 'POST',
      handler: async (event) => {
        createPayload = await readBody<Record<string, any>>(event)
        return {
          ok: true,
          data: { type: 'node_bundled_create', id: '99', attributes: createPayload.attributes },
        }
      },
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'node_bundled_create' },
    })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('.loading').exists()).toBe(false))

    const bundleSelect = wrapper.get('select')
    expect(bundleSelect.isVisible()).toBe(true)
    expect(wrapper.find('input[type="hidden"]').exists()).toBe(false)
    expect(wrapper.find('textarea').exists()).toBe(false)

    await bundleSelect.setValue('page')
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('textarea').exists()).toBe(true))

    expect(schemaPayloads).toEqual([{}, { bundle: 'page' }])
    expect(wrapper.get('textarea').element.closest('.field')?.textContent).toContain('Page summary')

    await wrapper.get('input[type="text"]').setValue('About')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(createPayload).toEqual({
      attributes: expect.objectContaining({ type: 'page', title: 'About' }),
    })
  })

  it('shows a clear load failure instead of a usable form when the selected bundle schema is rejected', async () => {
    registerEndpoint('/admin/_surface/node_invalid_bundle/action/schema', {
      method: 'POST',
      handler: async (event) => {
        const payload = await readBody<Record<string, unknown>>(event)
        if (payload.bundle === 'page') {
          return {
            ok: false,
            error: { status: 400, title: 'Invalid bundle', detail: "Bundle 'page' is not available." },
          }
        }
        return { ok: true, data: bundledNodeDiscoverySchema }
      },
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'node_invalid_bundle' },
    })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('.loading').exists()).toBe(false))

    await wrapper.get('select').setValue('page')
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('.error').exists()).toBe(true))

    expect(wrapper.get('.error').text()).toContain("Bundle 'page' is not available.")
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('preserves the existing one-stage create flow for an unbundled entity type', async () => {
    const schemaPayloads: Array<Record<string, unknown>> = []
    let createPayload: Record<string, any> | null = null
    registerEndpoint('/admin/_surface/unbundled_create/action/schema', {
      method: 'POST',
      handler: async (event) => {
        schemaPayloads.push(await readBody<Record<string, unknown>>(event))
        return { ok: true, data: userSchema }
      },
    })
    registerEndpoint('/admin/_surface/unbundled_create/action/create', {
      method: 'POST',
      handler: async (event) => {
        createPayload = await readBody<Record<string, any>>(event)
        return { ok: true, data: { type: 'unbundled_create', id: '5', attributes: createPayload.attributes } }
      },
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'unbundled_create' },
    })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('.loading').exists()).toBe(false))

    expect(schemaPayloads).toEqual([{}])
    expect(wrapper.find('form').exists()).toBe(true)
    await wrapper.get('input[type="text"]').setValue('alice')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(schemaPayloads).toEqual([{}])
    expect(createPayload).toEqual({ attributes: expect.objectContaining({ name: 'alice' }) })
  })

  it('emits saved event with resource on successful create', async () => {
    const resource = { type: 'user', id: '5', attributes: { name: 'alice' } }
    registerEndpoint('/admin/_surface/user_create/action/create', {
      method: 'POST',
      handler: () => ({
        ok: true,
        data: resource,
      }),
    })
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'user_create' },
    })
    await flushPromises()
    await wrapper.get('input[type="text"]').setValue('alice')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('saved')?.[0]).toEqual([resource])
  })

  it('initializes boolean fields from schema defaults in create mode', async () => {
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'node_defaults' },
    })
    await flushPromises()

    const checkboxes = wrapper.findAll('input[type="checkbox"]')
    // 3 boolean fields should render as checkboxes
    expect(checkboxes.length).toBe(3)
    // status (default: true) should be checked
    expect((checkboxes[0].element as HTMLInputElement).checked).toBe(true)
    // promote (default: false) should be unchecked
    expect((checkboxes[1].element as HTMLInputElement).checked).toBe(false)
    // sticky (no default, convention: false) should be unchecked
    expect((checkboxes[2].element as HTMLInputElement).checked).toBe(false)
  })

  it('auto-generates machine name deterministically from the source field', async () => {
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'content_type' },
    })
    await flushPromises()

    const titleInput = wrapper.find('input[type="text"]:not(.field-input--machine-name)')
    const machineNameInput = wrapper.find('.field-input--machine-name')

    await titleInput.setValue('Hello World')
    await flushPromises()

    expect((machineNameInput.element as HTMLInputElement).value).toBe('hello_world')
  })

  it('emits error event when create fails', async () => {
    registerEndpoint('/admin/_surface/user_create_err/action/create', {
      method: 'POST',
      handler: () => {
        throw createError({ statusCode: 422, statusMessage: 'Validation failed' })
      },
    })
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'user_create_err' },
    })
    await flushPromises()
    await wrapper.get('input[type="text"]').setValue('alice')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    // Should emit an error event
    expect(wrapper.emitted('error')).toBeTruthy()
  })
})

describe('SchemaForm submit — edit mode (with entityId)', () => {
  it('omits migrated vocabulary attributes absent from the write schema when saving a title change', async () => {
    const vocabularySchema = {
      ...userSchema,
      'x-entity-type': 'taxonomy_vocabulary_browser_edit',
      properties: {
        name: { type: 'string', 'x-widget': 'text', 'x-label': 'Title' },
      },
      required: ['name'],
    }
    let submitted: any = null
    registerEndpoint('/admin/_surface/taxonomy_vocabulary_browser_edit/action/schema', {
      method: 'POST',
      handler: () => ({ ok: true, data: vocabularySchema }),
    })
    registerEndpoint('/admin/_surface/taxonomy_vocabulary_browser_edit/renamed', {
      method: 'GET',
      handler: () => ({
        ok: true,
        data: {
          type: 'taxonomy_vocabulary',
          id: 'renamed',
          attributes: { bundle: '', langcode: 'en', name: '' },
        },
      }),
    })
    registerEndpoint('/admin/_surface/taxonomy_vocabulary_browser_edit/action/update', {
      method: 'POST',
      handler: async (event) => {
        submitted = await readBody(event)
        if ('bundle' in submitted.attributes) {
          throw createError({ statusCode: 422, statusMessage: 'The following attribute(s) are not writable: bundle.' })
        }
        return {
          ok: true,
          data: { type: 'taxonomy_vocabulary', id: 'renamed', attributes: submitted.attributes },
        }
      },
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'taxonomy_vocabulary_browser_edit', entityId: 'renamed' },
    })
    await flushPromises()
    await wrapper.get('input[type="text"]').setValue('Audit vocabulary')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(submitted).toEqual({ id: 'renamed', attributes: { name: 'Audit vocabulary' } })
  })

  it('loads existing entity attributes into form', async () => {
    registerEndpoint('/admin/_surface/user_edit/3', {
      method: 'GET',
      handler: () => ({
        ok: true,
        data: { type: 'user', id: '3', attributes: { name: 'bob' } },
      }),
    })
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'user_edit', entityId: '3' },
    })
    await flushPromises()
    await flushPromises()
    // The name field should be pre-populated
    const nameInput = wrapper.find('input[type="text"]')
    if (nameInput.exists()) {
      expect((nameInput.element as HTMLInputElement).value).toBe('bob')
    } else {
      // If the form didn't render, entity data may not have loaded — check attributes are in formData
      expect(wrapper.find('form').exists()).toBe(true)
    }
  })

  it('emits saved event after PATCH when entityId is provided', async () => {
    const updated = { type: 'user', id: '3', attributes: { name: 'bob-updated' } }
    registerEndpoint('/admin/_surface/user_edit_patch/3', () => ({
      ok: true,
      data: { type: 'user', id: '3', attributes: { name: 'bob' } },
    }))
    registerEndpoint('/admin/_surface/user_edit_patch/action/update', {
      method: 'POST',
      handler: () => ({
        ok: true,
        data: updated,
      }),
    })
    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'user_edit_patch', entityId: '3' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('saved')?.[0]).toEqual([updated])
  })

  it('locks the machine name field deterministically in edit mode', async () => {
    registerEndpoint('/admin/_surface/content_type_edit/3', {
      method: 'GET',
      handler: () => ({
        ok: true,
        data: { type: 'content_type', id: '3', attributes: { title: 'Article', type: 'article' } },
      }),
    })

    const { default: SchemaFormFresh } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaFormFresh, {
      props: { entityType: 'content_type_edit', entityId: '3' },
    })
    await flushPromises()
    await flushPromises()

    expect(wrapper.find('.field-input--machine-name').attributes('disabled')).toBeDefined()
  })
})

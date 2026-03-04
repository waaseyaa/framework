// packages/admin/tests/components/schema/SchemaForm.test.ts
import { describe, it, expect, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import SchemaForm from '~/components/schema/SchemaForm.vue'
import { userSchema } from '../../fixtures/schemas'

const schemaResponse = { meta: { schema: userSchema } }

describe('SchemaForm loading and error states', () => {
  it('shows loading state while schema is fetching', async () => {
    // Never resolves — component stays in loading state
    vi.stubGlobal('$fetch', vi.fn().mockReturnValue(new Promise(() => {})))
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user' },
    })
    await flushPromises()
    expect(wrapper.find('.loading').exists()).toBe(true)
  })

  it('shows error state when schema fetch fails', async () => {
    vi.stubGlobal(
      '$fetch',
      vi.fn().mockRejectedValue({ message: 'Schema not found' }),
    )
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user' },
    })
    await flushPromises()
    expect(wrapper.find('.error').exists()).toBe(true)
  })

  it('renders form fields after schema loads', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue(schemaResponse))
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user' },
    })
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(true)
  })
})

describe('SchemaForm submit — create mode (no entityId)', () => {
  it('emits saved event with resource on successful create', async () => {
    const resource = { type: 'user', id: '5', attributes: { name: 'alice' } }
    vi.stubGlobal(
      '$fetch',
      vi.fn()
        .mockResolvedValueOnce(schemaResponse) // schema fetch
        .mockResolvedValueOnce({ jsonapi: { version: '1.0' }, data: resource }), // create
    )
    // Use unique entityType to avoid schemaCache collision with previous tests
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user_create' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('saved')?.[0]).toEqual([resource])
  })

  it('initializes boolean fields from schema defaults in create mode', async () => {
    const schemaWithDefaults = {
      meta: {
        schema: {
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
        },
      },
    }
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue(schemaWithDefaults))
    const wrapper = await mountSuspended(SchemaForm, {
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

  it('emits error event when create fails', async () => {
    vi.stubGlobal(
      '$fetch',
      vi.fn()
        .mockResolvedValueOnce(schemaResponse)
        .mockRejectedValueOnce({
          data: { errors: [{ detail: 'Validation failed' }] },
        }),
    )
    // Use unique entityType to avoid schemaCache collision
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user_create_err' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('error')?.[0]).toEqual(['Validation failed'])
  })
})

describe('SchemaForm submit — edit mode (with entityId)', () => {
  it('loads existing entity attributes into form', async () => {
    const resource = { type: 'user', id: '3', attributes: { name: 'bob' } }
    vi.stubGlobal(
      '$fetch',
      vi.fn()
        .mockResolvedValueOnce(schemaResponse)      // schema
        .mockResolvedValueOnce({ jsonapi: { version: '1.0' }, data: resource }), // get
    )
    // Use unique entityType to avoid schemaCache collision
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user_edit', entityId: '3' },
    })
    await flushPromises()
    // The name field should be pre-populated
    const nameInput = wrapper.find('input[type="text"]')
    expect((nameInput.element as HTMLInputElement).value).toBe('bob')
  })

  it('emits saved event after PATCH when entityId is provided', async () => {
    const existing = { type: 'user', id: '3', attributes: { name: 'bob' } }
    const updated = { type: 'user', id: '3', attributes: { name: 'bob-updated' } }
    const mockFetch = vi.fn()
      .mockResolvedValueOnce(schemaResponse)      // schema
      .mockResolvedValueOnce({ jsonapi: { version: '1.0' }, data: existing }) // get
      .mockResolvedValueOnce({ jsonapi: { version: '1.0' }, data: updated }) // update (PATCH)
    vi.stubGlobal('$fetch', mockFetch)
    const wrapper = await mountSuspended(SchemaForm, {
      props: { entityType: 'user_edit_patch', entityId: '3' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    // Verify PATCH was sent (third call), not POST
    expect(mockFetch.mock.calls[2][1]).toEqual(expect.objectContaining({ method: 'PATCH' }))
    expect(wrapper.emitted('saved')?.[0]).toEqual([updated])
  })
})

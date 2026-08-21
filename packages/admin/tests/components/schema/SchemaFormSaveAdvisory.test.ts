import { describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended, registerEndpoint } from '@nuxt/test-utils/runtime'
import { readBody } from 'h3'

const TOKEN = 'a'.repeat(64)
const schema = {
  $schema: 'https://json-schema.org/draft-07/schema#',
  title: 'Page',
  type: 'object',
  'x-entity-type': 'advisory_page',
  'x-translatable': false,
  'x-revisionable': false,
  properties: {
    title: { type: 'string', 'x-widget': 'text', 'x-label': 'Title', 'x-required': true },
  },
  required: ['title'],
}

describe('SchemaForm save advisory acknowledgement', () => {
  it('renders an accessible review and confirms the exact captured candidate explicitly', async () => {
    const payloads: Array<Record<string, any>> = []
    registerEndpoint('/admin/_surface/advisory_page/action/schema', {
      method: 'POST',
      handler: () => ({ ok: true, data: schema }),
    })
    registerEndpoint('/admin/_surface/advisory_page/action/create', {
      method: 'POST',
      handler: async (event) => {
        const payload = await readBody<Record<string, any>>(event)
        payloads.push(payload)
        if (payload.save_advisory_acknowledgements?.[0] !== TOKEN) {
          return {
            ok: false,
            error: {
              status: 428,
              title: 'Precondition Required',
              detail: 'Review and acknowledge the save advisory before retrying.',
              code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
              meta: {
                save_advisories: [{
                  code: 'RESERVED_ROUTE_VALUE',
                  field: 'title',
                  severity: 'warning',
                  message: 'The short route is reserved; this page remains at /pages/news.',
                  acknowledgement: TOKEN,
                }],
              },
            },
          }
        }
        return { ok: true, data: { type: 'advisory_page', id: '7', attributes: payload.attributes } }
      },
    })

    vi.resetModules()
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaForm, { props: { entityType: 'advisory_page' } })
    await flushPromises()
    await wrapper.get('input[type="text"]').setValue('News')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    await vi.waitFor(() => {
      expect(wrapper.find('[data-testid="save-advisory-review"]').exists()).toBe(true)
    })

    const review = wrapper.get('[data-testid="save-advisory-review"]')
    expect(review.attributes('role')).toBe('status')
    expect(review.text()).toContain('The short route is reserved')
    expect(wrapper.emitted('saved')).toBeUndefined()
    expect(payloads).toEqual([{ attributes: { title: 'News' } }])

    await review.get('button').trigger('click')
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.emitted('saved')).toBeTruthy())

    expect(payloads).toEqual([
      { attributes: { title: 'News' } },
      { attributes: { title: 'News' }, save_advisory_acknowledgements: [TOKEN] },
    ])
    expect(wrapper.emitted('saved')?.[0]?.[0]).toMatchObject({ id: '7' })
  })

  it('clears a pending review when the operator edits the candidate', async () => {
    registerEndpoint('/admin/_surface/advisory_page_edit/action/schema', {
      method: 'POST',
      handler: () => ({ ok: true, data: { ...schema, 'x-entity-type': 'advisory_page_edit' } }),
    })
    registerEndpoint('/admin/_surface/advisory_page_edit/action/create', {
      method: 'POST',
      handler: () => ({
        ok: false,
        error: {
          status: 428,
          title: 'Precondition Required',
          code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
          meta: {
            save_advisories: [{
              code: 'RESERVED_ROUTE_VALUE', field: 'title', severity: 'warning',
              message: 'Review this title.', acknowledgement: TOKEN,
            }],
          },
        },
      }),
    })

    vi.resetModules()
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaForm, { props: { entityType: 'advisory_page_edit' } })
    await flushPromises()
    const input = wrapper.get('input[type="text"]')
    await input.setValue('News')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-testid="save-advisory-review"]').exists()).toBe(true)

    await input.setValue('Events')

    expect(wrapper.find('[data-testid="save-advisory-review"]').exists()).toBe(false)
  })

  it('does not treat a codeless HTTP 428 as save-advisory review', async () => {
    registerEndpoint('/admin/_surface/advisory_page_token/action/schema', {
      method: 'POST',
      handler: () => ({ ok: true, data: { ...schema, 'x-entity-type': 'advisory_page_token' } }),
    })
    registerEndpoint('/admin/_surface/advisory_page_token/action/create', {
      method: 'POST',
      handler: () => ({
        ok: false,
        error: {
          status: 428,
          title: 'Precondition required',
          detail: 'Reload the entity before attempting this mutation.',
          meta: {
            reason: 'policy.internal.reason',
            token: 'session-secret',
            save_advisories: [{
              code: 'RESERVED_ROUTE_VALUE', field: 'title', severity: 'warning',
              message: 'Must not render without the advisory code.', acknowledgement: TOKEN,
            }],
          },
        },
      }),
    })

    vi.resetModules()
    const { default: SchemaForm } = await import('~/components/schema/SchemaForm.vue')
    const wrapper = await mountSuspended(SchemaForm, { props: { entityType: 'advisory_page_token' } })
    await flushPromises()
    await wrapper.get('input[type="text"]').setValue('News')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-testid="save-advisory-review"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Reload the entity before attempting this mutation.')
  })
})

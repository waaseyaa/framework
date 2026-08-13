import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { describe, expect, it, vi } from 'vitest'

const { pageMetaSpy } = vi.hoisted(() => ({ pageMetaSpy: vi.fn() }))

mockNuxtImport('useRoute', () => () => ({
  params: { entityType: 'node', id: 'create' },
  query: { bundle: 'community_event' },
}))
mockNuxtImport('definePageMeta', () => pageMetaSpy)

describe('entity-editor embed route', () => {
  it('mounts the shared schema editor without the Admin SPA shell', async () => {
    const { default: EntityEditorEmbed } = await import('~/pages/entity-editor-embed/[entityType]/[id].vue')
    const wrapper = await mountSuspended(EntityEditorEmbed, {
      global: {
        stubs: {
          EntityEditorWorkspace: {
            props: ['entityType', 'entityId', 'initialBundle'],
            template: '<div data-testid="shared-entity-editor" :data-entity-type="entityType" :data-entity-id="entityId" :data-bundle="initialBundle" />',
          },
        },
      },
    })
    await flushPromises()

    expect(pageMetaSpy).toHaveBeenCalledWith({ layout: false })
    expect(wrapper.get('[data-testid="shared-entity-editor"]').attributes()).toMatchObject({
      'data-entity-type': 'node',
      'data-bundle': 'community_event',
    })
  })
})

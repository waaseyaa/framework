import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { describe, expect, it, vi } from 'vitest'

const { pageMetaSpy, lifecycleSpy } = vi.hoisted(() => ({ pageMetaSpy: vi.fn(), lifecycleSpy: vi.fn() }))

vi.mock('~/runtime/embedLifecycle', () => ({ postEmbedLifecycle: lifecycleSpy }))

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
            emits: ['ready', 'dirty', 'saved', 'deleted', 'failure', 'transitioned'],
            template: '<div data-testid="shared-entity-editor" :data-entity-type="entityType" :data-entity-id="entityId" :data-bundle="initialBundle"><button data-testid="ready" @click="$emit(\'ready\')" /><button data-testid="dirty" @click="$emit(\'dirty\', true)" /><button data-testid="failure" @click="$emit(\'failure\', { kind: \'permission-denied\', status: 403 })" /><button data-testid="transition" @click="$emit(\'transitioned\', { transition: \'publish\', from: \'review\', to: \'published\', public_changed: true })" /></div>',
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
    await wrapper.get('[data-testid="ready"]').trigger('click')
    await wrapper.get('[data-testid="dirty"]').trigger('click')
    await wrapper.get('[data-testid="failure"]').trigger('click')
    await wrapper.get('[data-testid="transition"]').trigger('click')

    expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'ready', surface: 'entity-editor', entityType: 'node' }))
    expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'dirty', dirty: true }))
    expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'failure', failure: { kind: 'permission-denied', status: 403 } }))
    expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({
      event: 'transitioned',
      transition: { state: 'published', publicChanged: true },
    }))
  })
})

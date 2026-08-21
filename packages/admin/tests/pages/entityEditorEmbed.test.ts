import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { describe, expect, it, vi } from 'vitest'

const { pageMetaSpy, lifecycleSpy, navigateToSpy } = vi.hoisted(() => ({
  pageMetaSpy: vi.fn(),
  lifecycleSpy: vi.fn(),
  navigateToSpy: vi.fn(async () => {}),
}))

vi.mock('~/runtime/embedLifecycle', () => ({ postEmbedLifecycle: lifecycleSpy }))

mockNuxtImport('useRoute', () => () => ({
  params: { entityType: 'node', id: 'create' },
  query: { bundle: 'community_event' },
}))
mockNuxtImport('definePageMeta', () => pageMetaSpy)
mockNuxtImport('navigateTo', () => navigateToSpy)

describe('entity-editor embed route', () => {
  it('mounts the shared schema editor without the Admin SPA shell', async () => {
    const postMessage = vi.fn()
    const originalParent = window.parent
    Object.defineProperty(window, 'parent', { configurable: true, value: { postMessage } })

    try {
      const { default: EntityEditorEmbed } = await import('~/pages/entity-editor-embed/[entityType]/[id].vue')
      const wrapper = await mountSuspended(EntityEditorEmbed, {
        global: {
          stubs: {
            EntityEditorWorkspace: {
              props: ['entityType', 'entityId', 'initialBundle'],
              emits: ['ready', 'dirty', 'saved', 'deleted', 'failure', 'transitioned'],
              template: '<div data-testid="shared-entity-editor" :data-entity-type="entityType" :data-entity-id="entityId" :data-bundle="initialBundle"><button data-testid="ready" @click="$emit(\'ready\')" /><button data-testid="dirty" @click="$emit(\'dirty\', true)" /><button data-testid="saved" @click="$emit(\'saved\', { id: \'99\' })" /><button data-testid="deleted" @click="$emit(\'deleted\', \'99\')" /><button data-testid="failure" @click="$emit(\'failure\', { kind: \'permission-denied\', status: 403 })" /><button data-testid="transition" @click="$emit(\'transitioned\', { transition: \'publish\', from: \'review\', to: \'published\', public_changed: true })" /></div>',
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
      await wrapper.get('[data-testid="saved"]').trigger('click')
      await wrapper.get('[data-testid="deleted"]').trigger('click')
      await flushPromises()

      expect(lifecycleSpy.mock.calls.map((call) => call[0].event)).toEqual([
        'ready',
        'dirty',
        'failure',
        'transitioned',
        'saved',
        'deleted',
      ])
      expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'ready', surface: 'entity-editor', entityType: 'node' }))
      expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'dirty', dirty: true }))
      expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'failure', failure: { kind: 'permission-denied', status: 403 } }))
      expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({
        event: 'transitioned',
        transition: { state: 'published', publicChanged: true },
      }))
      expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'saved', entityId: '99' }))
      expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'deleted', entityId: '99' }))
      expect(postMessage).toHaveBeenCalledTimes(2)
      expect(postMessage).toHaveBeenNthCalledWith(1, {
        type: 'waaseyaa.entity-editor.saved',
        entityType: 'node',
        entityId: '99',
      }, window.location.origin)
      expect(postMessage).toHaveBeenNthCalledWith(2, {
        type: 'waaseyaa.entity-editor.deleted',
        entityType: 'node',
        entityId: '99',
      }, window.location.origin)
    } finally {
      Object.defineProperty(window, 'parent', { configurable: true, value: originalParent })
    }
  })
})

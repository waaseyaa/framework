import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { describe, expect, it, vi } from 'vitest'

const { pageMetaSpy, lifecycleSpy } = vi.hoisted(() => ({ pageMetaSpy: vi.fn(), lifecycleSpy: vi.fn() }))

vi.mock('~/runtime/embedLifecycle', () => ({ postEmbedLifecycle: lifecycleSpy }))

mockNuxtImport('useRoute', () => () => ({
  params: { surface: 'page', id: '42' },
}))
mockNuxtImport('definePageMeta', () => pageMetaSpy)

describe('page-builder embed route', () => {
  it('mounts the exact shared workspace without the Admin SPA shell', async () => {
    const { default: PageBuilderEmbed } = await import('~/pages/page-builder-embed/[surface]/[id].vue')
    const wrapper = await mountSuspended(PageBuilderEmbed, {
      global: {
        stubs: {
          PageBuilderWorkspace: {
            props: ['surface', 'entityId'],
            emits: ['ready', 'dirty', 'saved', 'failure'],
            template: '<div data-testid="shared-page-builder" :data-surface="surface" :data-entity-id="entityId"><button data-testid="ready" @click="$emit(\'ready\')" /><button data-testid="dirty" @click="$emit(\'dirty\', true)" /><button data-testid="saved" @click="$emit(\'saved\')" /></div>',
          },
        },
      },
    })
    await flushPromises()

    expect(pageMetaSpy).toHaveBeenCalledWith({ layout: false })
    expect(wrapper.get('[data-testid="shared-page-builder"]').attributes()).toMatchObject({
      'data-surface': 'page',
      'data-entity-id': '42',
    })
    await wrapper.get('[data-testid="ready"]').trigger('click')
    await wrapper.get('[data-testid="dirty"]').trigger('click')
    await wrapper.get('[data-testid="saved"]').trigger('click')

    expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'ready', surface: 'page-builder', surfaceId: 'page', entityId: '42' }))
    expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'dirty', dirty: true }))
    expect(lifecycleSpy).toHaveBeenCalledWith(expect.objectContaining({ event: 'saved' }))
  })
})

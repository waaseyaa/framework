import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { describe, expect, it, vi } from 'vitest'

const { pageMetaSpy } = vi.hoisted(() => ({ pageMetaSpy: vi.fn() }))

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
            template: '<div data-testid="shared-page-builder" :data-surface="surface" :data-entity-id="entityId" />',
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
  })
})

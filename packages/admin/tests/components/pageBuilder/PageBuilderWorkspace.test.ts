import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { mountSuspended } from '@nuxt/test-utils/runtime'

const { ref } = require('vue') as typeof import('vue')

const definitions = ref({
  blocks: [{ id: 'copy', version: 1, label: 'Copy', renderer: 'copy', config_schema: { type: 'object', properties: {} } }],
  layouts: [{ id: 'one', version: 1, regions: ['main'], required_regions: ['main'], allowed_blocks: ['copy'] }],
  templates: [{ id: 'standard', version: 1, allowed_layouts: ['one'], allowed_blocks: ['copy'] }],
})
const draft = ref({
  entity_id: '42',
  entity_revision_id: 7,
  document_fingerprint: 'a'.repeat(64),
  document: {
    schema: 'waaseyaa.layout' as const,
    version: 1,
    template: { id: 'standard', version: 1 },
    sections: [{
      id: 'section',
      layout: { id: 'one', version: 1 },
      regions: {
        main: [
          { id: 'first', type: 'copy', version: 1, config: {} },
          { id: 'second', type: 'copy', version: 1, config: {} },
        ],
      },
    }],
  },
})

const { applyMock, loadMock, refreshPreviewMock } = vi.hoisted(() => ({
  applyMock: vi.fn(),
  loadMock: vi.fn(),
  refreshPreviewMock: vi.fn(),
}))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({ t: (key: string) => key }),
}))

vi.mock('~/composables/usePageBuilder', () => ({
  usePageBuilder: () => ({
    definitions,
    draft,
    previewUrl: ref('/preview'),
    loading: ref(false),
    saving: ref(false),
    error: ref(null),
    load: loadMock,
    apply: applyMock,
    refreshPreview: refreshPreviewMock,
  }),
}))

beforeEach(() => {
  applyMock.mockReset().mockResolvedValue(true)
  loadMock.mockReset().mockResolvedValue(undefined)
  refreshPreviewMock.mockReset().mockResolvedValue(undefined)
  vi.stubGlobal('crypto', { randomUUID: () => '11111111-2222-4333-8444-555555555555' })
})

async function mountWorkspace() {
  const { default: PageBuilderWorkspace } = await import('~/components/page-builder/PageBuilderWorkspace.vue')
  const wrapper = await mountSuspended(PageBuilderWorkspace, { props: { surface: 'page', entityId: '42' } })
  await flushPromises()
  return wrapper
}

function button(wrapper: Awaited<ReturnType<typeof mountWorkspace>>, text: string) {
  const match = wrapper.findAll('button').find(candidate => candidate.text().includes(text))
  if (!match) throw new Error(`Missing button: ${text}`)
  return match
}

describe('PageBuilderWorkspace block controls', () => {
  it('moves the selected block down through the governed command surface', async () => {
    const wrapper = await mountWorkspace()

    await button(wrapper, 'page_builder_move_down').trigger('click')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledWith({
      type: 'move_block',
      block_id: 'first',
      destination_section_id: 'section',
      destination_region_id: 'main',
      position: 1,
    })
    expect(refreshPreviewMock).toHaveBeenCalled()
  })

  it('moves the second block up and duplicates through explicit commands', async () => {
    const wrapper = await mountWorkspace()
    const outlineButtons = wrapper.findAll('.page-builder__outline-block')
    await outlineButtons[1]!.trigger('click')

    await button(wrapper, 'page_builder_move_up').trigger('click')
    await button(wrapper, 'page_builder_duplicate_block').trigger('click')
    await flushPromises()

    expect(applyMock).toHaveBeenNthCalledWith(1, {
      type: 'move_block',
      block_id: 'second',
      destination_section_id: 'section',
      destination_region_id: 'main',
      position: 0,
    })
    expect(applyMock).toHaveBeenNthCalledWith(2, {
      type: 'duplicate_block',
      source_block_id: 'second',
      duplicate_block_id: 'blk_11111111222243338444555555555555',
    })
  })
})

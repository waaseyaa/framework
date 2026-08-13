import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { PageBuilderDefinitions, PageBuilderDraft } from '~/contracts/pageBuilder'

const { definitionsRef, draftRef, previewUrlRef, loadingRef, savingRef, errorRef, loadMock, applyMock, refreshPreviewMock } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    definitionsRef: ref<PageBuilderDefinitions | null>(null),
    draftRef: ref<PageBuilderDraft | null>(null),
    previewUrlRef: ref<string | null>(null),
    loadingRef: ref(false),
    savingRef: ref(false),
    errorRef: ref<string | null>(null),
    loadMock: vi.fn(),
    applyMock: vi.fn(),
    refreshPreviewMock: vi.fn(),
  }
})

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({
    t: (key: string, values?: Record<string, string>) => values
      ? `${key}:${Object.values(values).join(',')}`
      : key,
  }),
}))

vi.mock('~/composables/usePageBuilder', () => ({
  usePageBuilder: () => ({
    definitions: definitionsRef,
    draft: draftRef,
    previewUrl: previewUrlRef,
    loading: loadingRef,
    saving: savingRef,
    error: errorRef,
    load: loadMock,
    apply: applyMock,
    refreshPreview: refreshPreviewMock,
  }),
}))

const definitions: PageBuilderDefinitions = {
  blocks: [{
    id: 'rich_text',
    version: 1,
    label: 'Rich text',
    renderer: 'content.rich_text',
    config_schema: {
      type: 'object',
      properties: { body: { type: 'string', title: 'Body', format: 'textarea' } },
    },
  }],
  layouts: [{
    id: 'one_column',
    version: 1,
    regions: ['main'],
    required_regions: ['main'],
    allowed_blocks: ['rich_text'],
  }],
  templates: [{ id: 'standard', version: 1, allowed_layouts: ['one_column'], allowed_blocks: ['rich_text'] }],
}

const draft: PageBuilderDraft = {
  entity_id: '42',
  entity_revision_id: 7,
  document_fingerprint: 'a'.repeat(64),
  document: {
    schema: 'waaseyaa.layout',
    version: 1,
    template: { id: 'standard', version: 1 },
    sections: [{
      id: 'sec_main',
      layout: { id: 'one_column', version: 1 },
      regions: { main: [{ id: 'blk_intro', type: 'rich_text', version: 1, config: { body: 'Welcome' } }] },
    }],
  },
}

beforeEach(() => {
  definitionsRef.value = structuredClone(definitions)
  draftRef.value = structuredClone(draft)
  previewUrlRef.value = '/preview/page/42?revision=7'
  loadingRef.value = false
  savingRef.value = false
  errorRef.value = null
  loadMock.mockReset().mockResolvedValue(undefined)
  applyMock.mockReset().mockResolvedValue(true)
  refreshPreviewMock.mockReset().mockResolvedValue(true)
})

async function mountWorkspace() {
  const { default: PageBuilderWorkspace } = await import('~/components/page-builder/PageBuilderWorkspace.vue')
  const wrapper = await mountSuspended(PageBuilderWorkspace, { props: { surface: 'page', entityId: '42' } })
  await flushPromises()
  return wrapper
}

describe('PageBuilderWorkspace', () => {
  it('presents the library, exact preview, inspector, and page outline as one workspace', async () => {
    const wrapper = await mountWorkspace()

    expect(loadMock).toHaveBeenCalledOnce()
    expect(refreshPreviewMock).toHaveBeenCalledOnce()
    expect(wrapper.find('iframe').attributes('src')).toBe('/preview/page/42?revision=7')
    expect(wrapper.text()).toContain('page_builder_revision:7')
    expect(wrapper.text()).toContain('Rich text')
    expect(wrapper.text()).toContain('one_column')
    expect(wrapper.get('textarea').element.value).toBe('Welcome')
  })

  it('applies inspector changes as a guarded command and refreshes the exact preview', async () => {
    const wrapper = await mountWorkspace()
    refreshPreviewMock.mockClear()

    await wrapper.get('textarea').setValue('Boozhoo')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledWith({
      type: 'configure_block',
      block_id: 'blk_intro',
      config: { body: 'Boozhoo' },
    })
    expect(refreshPreviewMock).toHaveBeenCalledOnce()
  })

  it('adds a registered block without allowing free-form markup or renderer names', async () => {
    const wrapper = await mountWorkspace()
    refreshPreviewMock.mockClear()
    const randomUUID = vi.spyOn(crypto, 'randomUUID').mockReturnValue('11111111-2222-4333-8444-555555555555')

    await wrapper.get('.page-builder__block-card').trigger('click')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledWith({
      type: 'add_block',
      section_id: 'sec_main',
      region_id: 'main',
      position: 1,
      block: {
        id: 'blk_11111111222243338444555555555555',
        type: 'rich_text',
        version: 1,
        config: {},
      },
    })
    expect(refreshPreviewMock).toHaveBeenCalledOnce()
    randomUUID.mockRestore()
  })

  it('adds a registered section and removes the selected block through guarded commands', async () => {
    const wrapper = await mountWorkspace()
    refreshPreviewMock.mockClear()
    const randomUUID = vi.spyOn(crypto, 'randomUUID').mockReturnValue('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')

    await wrapper.get('.page-builder__add-section').trigger('click')
    await flushPromises()
    expect(applyMock).toHaveBeenCalledWith({
      type: 'add_section',
      position: 1,
      section: {
        id: 'sec_aaaaaaaabbbb4ccc8dddeeeeeeeeeeee',
        layout: { id: 'one_column', version: 1 },
        regions: { main: [] },
      },
    })

    applyMock.mockClear()
    refreshPreviewMock.mockClear()
    await wrapper.get('.btn-danger').trigger('click')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledWith({ type: 'remove_block', block_id: 'blk_intro' })
    expect(refreshPreviewMock).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('page_builder_nothing_selected')
    randomUUID.mockRestore()
  })

  it('accepts block selection only from its same-origin exact-preview frame', async () => {
    const wrapper = await mountWorkspace()
    const iframe = wrapper.get('[data-page-builder-preview]').element as HTMLIFrameElement
    const alternateDraft = structuredClone(draft)
    alternateDraft.document.sections[0]!.regions.main!.push({
      id: 'blk_second',
      type: 'rich_text',
      version: 1,
      config: { body: 'Second' },
    })
    draftRef.value = alternateDraft
    await flushPromises()

    window.dispatchEvent(new MessageEvent('message', {
      origin: window.location.origin,
      source: iframe.contentWindow,
      data: { type: 'waaseyaa.page-builder.select', blockId: 'blk_second' },
    }))
    await flushPromises()

    const selected = wrapper.findAll('.page-builder__outline-block').find(button => button.attributes('aria-pressed') === 'true')
    expect(selected?.text()).toBe('Rich text')
  })
})

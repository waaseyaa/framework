import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import type { PageBuilderDefinitions, PageBuilderDraft, PageBuilderRevision } from '~/contracts/pageBuilder'

const { definitionsRef, draftRef, previewUrlRef, revisionsRef, comparedRevisionRef, loadingRef, savingRef, errorRef, loadMock, applyMock, refreshPreviewMock, loadHistoryMock, compareRevisionMock, restoreRevisionMock } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    definitionsRef: ref<PageBuilderDefinitions | null>(null),
    draftRef: ref<PageBuilderDraft | null>(null),
    previewUrlRef: ref<string | null>(null),
    revisionsRef: ref<PageBuilderRevision[]>([]),
    comparedRevisionRef: ref<PageBuilderDraft | null>(null),
    loadingRef: ref(false),
    savingRef: ref(false),
    errorRef: ref<string | null>(null),
    loadMock: vi.fn(),
    applyMock: vi.fn(),
    refreshPreviewMock: vi.fn(),
    loadHistoryMock: vi.fn(),
    compareRevisionMock: vi.fn(),
    restoreRevisionMock: vi.fn(),
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
    revisions: revisionsRef,
    comparedRevision: comparedRevisionRef,
    loading: loadingRef,
    saving: savingRef,
    error: errorRef,
    load: loadMock,
    apply: applyMock,
    refreshPreview: refreshPreviewMock,
    loadHistory: loadHistoryMock,
    compareRevision: compareRevisionMock,
    restoreRevision: restoreRevisionMock,
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
  revisionsRef.value = []
  comparedRevisionRef.value = null
  loadingRef.value = false
  savingRef.value = false
  errorRef.value = null
  loadMock.mockReset().mockResolvedValue(undefined)
  applyMock.mockReset().mockResolvedValue(true)
  refreshPreviewMock.mockReset().mockResolvedValue(true)
  loadHistoryMock.mockReset().mockResolvedValue(true)
  compareRevisionMock.mockReset().mockResolvedValue(true)
  restoreRevisionMock.mockReset().mockResolvedValue(true)
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
    expect(wrapper.text()).toContain('One column')
    expect(wrapper.text()).not.toContain('rich_text')
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

  it('uses the governed rich-text widget when the block schema requests it', async () => {
    const richTextDefinitions = structuredClone(definitions)
    richTextDefinitions.blocks[0]!.config_schema.properties = {
      body: { type: 'string', title: 'Body', 'x-widget': 'richtext' },
    }
    definitionsRef.value = richTextDefinitions
    const wrapper = await mountWorkspace()
    refreshPreviewMock.mockClear()

    const editor = wrapper.get('[contenteditable="true"]')
    editor.element.innerHTML = '<p>Boozhoo</p>'
    await editor.trigger('input')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledWith({
      type: 'configure_block',
      block_id: 'blk_intro',
      config: { body: '<p>Boozhoo</p>' },
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
    definitionsRef.value = structuredClone(definitions)
    definitionsRef.value.layouts.push({
      id: 'sidebar',
      version: 1,
      regions: ['content', 'sidebar'],
      required_regions: ['content', 'sidebar'],
      allowed_blocks: ['rich_text'],
    })
    const wrapper = await mountWorkspace()
    refreshPreviewMock.mockClear()
    const randomUUID = vi.spyOn(crypto, 'randomUUID').mockReturnValue('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')

    const sidebar = wrapper.findAll('.page-builder__add-section').find(button => button.text().includes('Sidebar'))!
    await sidebar.trigger('click')
    await flushPromises()
    expect(applyMock).toHaveBeenCalledWith({
      type: 'add_section',
      position: 1,
      section: {
        id: 'sec_aaaaaaaabbbb4ccc8dddeeeeeeeeeeee',
        layout: { id: 'sidebar', version: 1 },
        regions: { content: [], sidebar: [] },
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

  it('inserts a new block after the selected block in its actual section and region', async () => {
    const multiSectionDraft = structuredClone(draft)
    multiSectionDraft.document.sections.push({
      id: 'sec_secondary',
      layout: { id: 'one_column', version: 1 },
      regions: {
        main: [{ id: 'blk_secondary', type: 'rich_text', version: 1, config: { body: 'Secondary' } }],
      },
    })
    draftRef.value = multiSectionDraft
    const wrapper = await mountWorkspace()
    const randomUUID = vi.spyOn(crypto, 'randomUUID').mockReturnValue('11111111-2222-4333-8444-555555555555')

    const secondary = wrapper.findAll('.page-builder__outline-block').find(button => button.text() === 'Rich text' && button.attributes('aria-pressed') !== 'true')!
    await secondary.trigger('click')
    applyMock.mockClear()
    await wrapper.get('.page-builder__block-card').trigger('click')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledWith(expect.objectContaining({
      type: 'add_block',
      section_id: 'sec_secondary',
      region_id: 'main',
      position: 1,
    }))
    randomUUID.mockRestore()
  })

  it('moves a selected block into another allowed section region', async () => {
    const multiDefinitions = structuredClone(definitions)
    multiDefinitions.layouts.push({
      id: 'two_column',
      version: 1,
      regions: ['main', 'sidebar'],
      required_regions: ['main', 'sidebar'],
      allowed_blocks: ['rich_text'],
    })
    definitionsRef.value = multiDefinitions
    const multiSectionDraft = structuredClone(draft)
    multiSectionDraft.document.sections.push({
      id: 'sec_secondary',
      layout: { id: 'two_column', version: 1 },
      regions: { main: [], sidebar: [] },
    })
    draftRef.value = multiSectionDraft
    const wrapper = await mountWorkspace()
    applyMock.mockClear()

    await wrapper.get('[data-block-destination]').setValue('sec_secondary::sidebar')
    await wrapper.get('[data-move-block-to-region]').trigger('click')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledWith({
      type: 'move_block',
      block_id: 'blk_intro',
      destination_section_id: 'sec_secondary',
      destination_region_id: 'sidebar',
      position: 0,
    })
    expect(wrapper.text()).toContain('page_builder_block_moved_to_region')
  })

  it('offers complete guarded section manipulation from the shared outline', async () => {
    const multiDefinitions = structuredClone(definitions)
    multiDefinitions.layouts.push({
      id: 'two_column',
      version: 1,
      regions: ['main', 'sidebar'],
      required_regions: ['main', 'sidebar'],
      allowed_blocks: ['rich_text'],
    })
    definitionsRef.value = multiDefinitions
    const multiSectionDraft = structuredClone(draft)
    multiSectionDraft.document.sections.push({
      id: 'sec_secondary',
      layout: { id: 'one_column', version: 1 },
      regions: { main: [] },
    })
    draftRef.value = multiSectionDraft
    const confirm = vi.fn().mockReturnValue(true)
    Object.defineProperty(window, 'confirm', { configurable: true, value: confirm })
    const uuid = vi.spyOn(crypto, 'randomUUID')
      .mockReturnValueOnce('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')
      .mockReturnValueOnce('11111111-2222-4333-8444-555555555555')
      .mockReturnValueOnce('99999999-2222-4333-8444-555555555555')
    const wrapper = await mountWorkspace()

    await wrapper.get('[data-section-select="sec_secondary"]').trigger('click')
    applyMock.mockClear()
    await wrapper.get('[data-move-section-up]').trigger('click')
    expect(applyMock).toHaveBeenLastCalledWith({ type: 'move_section', section_id: 'sec_secondary', position: 0 })

    await wrapper.get('[data-section-layout]').setValue('two_column::1')
    await wrapper.get('[data-change-section-layout]').trigger('click')
    expect(applyMock).toHaveBeenLastCalledWith({
      type: 'change_section_layout',
      section_id: 'sec_secondary',
      layout_id: 'two_column',
      layout_version: 1,
    })

    await wrapper.get('[data-duplicate-section]').trigger('click')
    expect(applyMock).toHaveBeenLastCalledWith({
      type: 'add_section',
      position: 2,
      section: {
        id: 'sec_aaaaaaaabbbb4ccc8dddeeeeeeeeeeee',
        layout: { id: 'one_column', version: 1 },
        regions: { main: [] },
      },
    })

    await wrapper.get('[data-section-select="sec_main"]').trigger('click')
    await wrapper.get('[data-duplicate-section]').trigger('click')
    expect(applyMock).toHaveBeenLastCalledWith({
      type: 'add_section',
      position: 1,
      section: {
        id: 'sec_11111111222243338444555555555555',
        layout: { id: 'one_column', version: 1 },
        regions: {
          main: [{
            id: 'blk_99999999222243338444555555555555',
            type: 'rich_text',
            version: 1,
            config: { body: 'Welcome' },
          }],
        },
      },
    })

    await wrapper.get('[data-remove-section]').trigger('click')
    expect(confirm).toHaveBeenCalled()
    expect(applyMock).toHaveBeenLastCalledWith({ type: 'remove_section', section_id: 'sec_main' })
    uuid.mockRestore()
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

  it('compares exact revisions and restores the selected history entry as a new draft', async () => {
    revisionsRef.value = [{
      revision_id: 5,
      created_at: '2026-08-13T10:00:00Z',
      author_id: 12,
      log: 'Earlier landing page',
      is_current: false,
      is_latest: false,
      document_fingerprint: 'b'.repeat(64),
      block_count: 2,
    }]
    const prior = structuredClone(draft)
    prior.entity_revision_id = 5
    prior.document.sections[0]!.id = 'sec_prior'
    prior.document.sections[0]!.regions.main = [
      { id: 'blk_intro', type: 'rich_text', version: 1, config: { body: 'Earlier welcome' } },
      { id: 'blk_removed', type: 'rich_text', version: 1, config: { body: 'Removed' } },
    ]
    const current = structuredClone(draft)
    current.document.sections[0]!.regions.main!.push({
      id: 'blk_added',
      type: 'rich_text',
      version: 1,
      config: { body: 'Added' },
    })
    draftRef.value = current
    const confirm = vi.fn().mockReturnValue(true)
    Object.defineProperty(window, 'confirm', { configurable: true, value: confirm })
    const wrapper = await mountWorkspace()

    const historyButton = wrapper.findAll('button').find(button => button.text() === 'page_builder_history')!
    await historyButton.trigger('click')
    expect(loadHistoryMock).toHaveBeenCalledTimes(2)

    await wrapper.get('.page-builder__revision-card').trigger('click')
    expect(compareRevisionMock).toHaveBeenCalledWith(5)
    comparedRevisionRef.value = prior
    await flushPromises()

    expect(wrapper.text()).toContain('page_builder_page_structure')
    expect(wrapper.text()).toContain('page_builder_added')
    expect(wrapper.text()).toContain('page_builder_removed')
    expect(wrapper.text()).toContain('page_builder_changed')

    await wrapper.get('.page-builder__comparison .btn-primary').trigger('click')
    await flushPromises()
    expect(confirm).toHaveBeenCalledWith('page_builder_restore_confirm:5')
    expect(restoreRevisionMock).toHaveBeenCalledWith(5)
    expect(refreshPreviewMock).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('page_builder_revision_restored:5')
  })

  it('autosaves dirty block configuration after the idle interval', async () => {
    vi.useFakeTimers()
    const wrapper = await mountWorkspace()
    applyMock.mockClear()
    refreshPreviewMock.mockClear()
    loadHistoryMock.mockClear()

    await wrapper.get('textarea').setValue('Saved after a pause')
    await vi.advanceTimersByTimeAsync(1500)
    await flushPromises()

    expect(applyMock).toHaveBeenCalledWith({
      type: 'configure_block',
      block_id: 'blk_intro',
      config: { body: 'Saved after a pause' },
    })
    expect(refreshPreviewMock).toHaveBeenCalledOnce()
    expect(loadHistoryMock).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('page_builder_autosaved')
    vi.useRealTimers()
  })
})

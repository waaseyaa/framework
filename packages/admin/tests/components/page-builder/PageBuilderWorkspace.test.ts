import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { PageBuilderCommand, PageBuilderDefinitions, PageBuilderDraft, PageBuilderRevision } from '~/contracts/pageBuilder'

const { definitionsRef, draftRef, previewUrlRef, revisionsRef, comparedRevisionRef, loadingRef, savingRef, errorRef, failureRef, conflictRef, advisoryReviewRef, advisoryUnsupportedRef, loadMock, applyMock, loadLatestForConflictMock, retryConflictMock, dismissConflictMock, confirmAdvisoryReviewMock, declineAdvisoryReviewMock, dismissAdvisoryUnsupportedMock, refreshPreviewMock, loadHistoryMock, compareRevisionMock, restoreRevisionMock } = vi.hoisted(() => {
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
    failureRef: ref<{ kind: 'server', status?: number } | null>(null),
    conflictRef: ref<{ detail: string, latestLoaded: boolean } | null>(null),
    advisoryReviewRef: ref<{
      command: PageBuilderCommand
      advisories: Array<{ code: string, field: string, severity: 'warning', message: string, acknowledgement: string }>
      detail: string
      operationId: string
    } | null>(null),
    advisoryUnsupportedRef: ref<string | null>(null),
    loadMock: vi.fn(),
    applyMock: vi.fn(),
    loadLatestForConflictMock: vi.fn(),
    retryConflictMock: vi.fn(),
    dismissConflictMock: vi.fn(),
    confirmAdvisoryReviewMock: vi.fn(),
    declineAdvisoryReviewMock: vi.fn(),
    dismissAdvisoryUnsupportedMock: vi.fn(),
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
    failure: failureRef,
    conflict: conflictRef,
    advisoryReview: advisoryReviewRef,
    advisoryUnsupported: advisoryUnsupportedRef,
    load: loadMock,
    apply: applyMock,
    loadLatestForConflict: loadLatestForConflictMock,
    retryConflict: retryConflictMock,
    dismissConflict: dismissConflictMock,
    confirmAdvisoryReview: confirmAdvisoryReviewMock,
    declineAdvisoryReview: declineAdvisoryReviewMock,
    dismissAdvisoryUnsupported: dismissAdvisoryUnsupportedMock,
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

function applyCommandToDraft(command: PageBuilderCommand): void {
  if (!draftRef.value) return
  const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T
  const next = clone(draftRef.value)
  const sections = next.document.sections
  const locateBlock = (blockId: string) => {
    for (const section of sections) {
      for (const [regionId, regionBlocks] of Object.entries(section.regions)) {
        const position = regionBlocks.findIndex(block => block.id === blockId)
        if (position >= 0) return { section, regionId, regionBlocks, position, block: regionBlocks[position]! }
      }
    }
    return null
  }

  switch (command.type) {
    case 'add_block': {
      const section = sections.find(item => item.id === command.section_id)!
      section.regions[command.region_id]!.splice(command.position, 0, clone(command.block))
      break
    }
    case 'duplicate_block': {
      const source = locateBlock(command.source_block_id)!
      source.regionBlocks.splice(source.position + 1, 0, { ...clone(source.block), id: command.duplicate_block_id })
      break
    }
    case 'configure_block':
      locateBlock(command.block_id)!.block.config = clone(command.config)
      break
    case 'move_block': {
      const source = locateBlock(command.block_id)!
      const [block] = source.regionBlocks.splice(source.position, 1)
      const destination = sections.find(item => item.id === command.destination_section_id)!
      destination.regions[command.destination_region_id]!.splice(command.position, 0, block!)
      break
    }
    case 'remove_block': {
      const source = locateBlock(command.block_id)!
      source.regionBlocks.splice(source.position, 1)
      break
    }
    case 'add_section':
      sections.splice(command.position, 0, clone(command.section))
      break
    case 'duplicate_section': {
      const source = sections.findIndex(item => item.id === command.source_section_id)
      const duplicate = clone(sections[source]!)
      duplicate.id = command.duplicate_section_id
      for (const blocks of Object.values(duplicate.regions)) {
        for (const block of blocks) block.id = command.duplicate_block_ids[block.id]!
      }
      sections.splice(source + 1, 0, duplicate)
      break
    }
    case 'move_section': {
      const source = sections.findIndex(item => item.id === command.section_id)
      const [section] = sections.splice(source, 1)
      sections.splice(command.position, 0, section!)
      break
    }
    case 'remove_section': {
      const source = sections.findIndex(item => item.id === command.section_id)
      sections.splice(source, 1)
      break
    }
    case 'change_section_layout': {
      const section = sections.find(item => item.id === command.section_id)!
      const layout = definitionsRef.value!.layouts.find(item => item.id === command.layout_id && item.version === command.layout_version)!
      section.layout = { id: layout.id, version: layout.version }
      section.regions = Object.fromEntries(layout.regions.map(regionId => [regionId, section.regions[regionId] ?? []]))
      break
    }
  }

  next.entity_revision_id += 1
  next.document_fingerprint = String(next.entity_revision_id).padStart(64, '0')
  draftRef.value = next
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
  failureRef.value = null
  conflictRef.value = null
  advisoryReviewRef.value = null
  advisoryUnsupportedRef.value = null
  loadMock.mockReset().mockResolvedValue(undefined)
  applyMock.mockReset().mockImplementation(async (command: PageBuilderCommand) => {
    applyCommandToDraft(command)
    return true
  })
  loadLatestForConflictMock.mockReset().mockResolvedValue(true)
  retryConflictMock.mockReset().mockResolvedValue(true)
  dismissConflictMock.mockReset()
  confirmAdvisoryReviewMock.mockReset().mockResolvedValue(true)
  declineAdvisoryReviewMock.mockReset().mockImplementation(() => { advisoryReviewRef.value = null })
  dismissAdvisoryUnsupportedMock.mockReset()
  refreshPreviewMock.mockReset().mockResolvedValue(true)
  loadHistoryMock.mockReset().mockResolvedValue(true)
  compareRevisionMock.mockReset().mockResolvedValue(true)
  restoreRevisionMock.mockReset().mockResolvedValue(true)
})

async function mountWorkspace() {
  const { default: PageBuilderWorkspace } = await import('~/components/page-builder/PageBuilderWorkspace.vue')
  const wrapper = await mountSuspended(PageBuilderWorkspace, {
    props: { surface: 'page', entityId: '42' },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

async function decideConfirmation(confirm = true) {
  const selector = confirm ? '[data-testid="confirm-dialog-confirm"]' : '[data-testid="confirm-dialog-cancel"]'
  const button = document.querySelector<HTMLButtonElement>(selector)
  expect(button).not.toBeNull()
  button!.click()
  await flushPromises()
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
    expect(wrapper.emitted('ready')).toHaveLength(1)
  })

  it('forwards only the bounded lifecycle failure state', async () => {
    const wrapper = await mountWorkspace()
    failureRef.value = { kind: 'server', status: 503 }
    await nextTick()

    expect(wrapper.emitted('failure')?.[0]).toEqual([{ kind: 'server', status: 503 }])
  })

  it('allows governed preview widths to shrink the iframe below its content minimum', async () => {
    const wrapper = await mountWorkspace()
    const preview = wrapper.get('[data-page-builder-preview]')
    const viewportButtons = wrapper.findAll('.page-builder__viewport button')

    expect(preview.element.style.width).toBe('100%')
    await viewportButtons[2]!.trigger('click')

    expect(viewportButtons[2]!.attributes('aria-pressed')).toBe('true')
    expect(preview.element.style.width).toBe('390px')
    expect(preview.element.style.minWidth).toBe('0px')
    expect(preview.element.style.flexBasis).toBe('390px')
  })

  it('preserves a conflicting change and requires an explicit compare then reapply choice', async () => {
    conflictRef.value = { detail: 'The page changed.', latestLoaded: false }
    const wrapper = await mountWorkspace()

    expect(wrapper.get('[data-page-builder-conflict]').text()).toContain('page_builder_conflict_help')
    expect(wrapper.get('textarea').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-page-builder-conflict] button').trigger('click')
    await flushPromises()
    expect(loadLatestForConflictMock).toHaveBeenCalledOnce()

    conflictRef.value = { detail: 'The page changed.', latestLoaded: true }
    await nextTick()
    const actions = wrapper.findAll('[data-page-builder-conflict] button')
    expect(actions).toHaveLength(2)
    await actions[0]!.trigger('click')
    await flushPromises()
    expect(retryConflictMock).toHaveBeenCalledOnce()
  })

  describe('layout save advisory review (#2475)', () => {
    const advisory = {
      code: 'RESERVED_ROUTE_VALUE',
      field: 'slug',
      severity: 'warning' as const,
      message: 'This slug is reserved for a system route.',
      acknowledgement: 'b'.repeat(64),
    }
    const heldCommand: PageBuilderCommand = { type: 'remove_block', block_id: 'blk_intro' }

    function hold(): void {
      advisoryReviewRef.value = {
        command: heldCommand,
        advisories: [advisory],
        detail: 'This change needs review before it can be saved.',
        operationId: '00000000-0000-4000-8000-000000000001',
      }
    }

    it('renders each advisory field and message and holds editing while the review is open', async () => {
      hold()
      const wrapper = await mountWorkspace()

      const banner = wrapper.get('[data-page-builder-advisory]')
      expect(banner.text()).toContain('page_builder_advisory_title')
      expect(banner.text()).toContain('page_builder_advisory_help')
      expect(banner.text()).toContain('slug')
      expect(banner.text()).toContain('This slug is reserved for a system route.')
      // The received receipt is never rendered to the author.
      expect(banner.text()).not.toContain(advisory.acknowledgement)
      expect(wrapper.get('textarea').attributes('disabled')).toBeDefined()
    })

    it('retains the pending edit rather than discarding it when a save is held', async () => {
      const wrapper = await mountWorkspace()
      await wrapper.get('textarea').setValue('Reserved slug rewrite')
      expect(wrapper.emitted('dirty')?.at(-1)).toEqual([true])

      hold()
      await nextTick()

      expect(wrapper.get('textarea').element.value).toBe('Reserved slug rewrite')
      expect(wrapper.emitted('dirty')?.at(-1)).toEqual([true])
      expect(wrapper.emitted('saved')).toBeUndefined()
      wrapper.unmount()
    })

    it('confirms through the composable and only then clears dirty state and announces the save', async () => {
      const wrapper = await mountWorkspace()
      await wrapper.get('textarea').setValue('Reserved slug rewrite')
      hold()
      await nextTick()
      expect(wrapper.emitted('dirty')?.at(-1)).toEqual([true])

      await wrapper.get('[data-page-builder-advisory-confirm]').trigger('click')
      await flushPromises()

      expect(confirmAdvisoryReviewMock).toHaveBeenCalledOnce()
      expect(wrapper.emitted('dirty')?.at(-1)).toEqual([false])
      expect(wrapper.emitted('saved')).toHaveLength(1)
      expect(loadHistoryMock).toHaveBeenCalled()
      expect(wrapper.find('.sr-only').text()).toContain('page_builder_advisory_acknowledged')
      wrapper.unmount()
    })

    it('leaves the document unsaved and the editor intact when the review is declined', async () => {
      const wrapper = await mountWorkspace()
      await wrapper.get('textarea').setValue('Reserved slug rewrite')
      hold()
      await nextTick()

      await wrapper.get('[data-page-builder-advisory-decline]').trigger('click')
      await flushPromises()

      expect(declineAdvisoryReviewMock).toHaveBeenCalledOnce()
      expect(confirmAdvisoryReviewMock).not.toHaveBeenCalled()
      expect(wrapper.find('[data-page-builder-advisory]').exists()).toBe(false)
      expect(wrapper.get('textarea').element.value).toBe('Reserved slug rewrite')
      expect(wrapper.emitted('dirty')?.at(-1)).toEqual([true])
      expect(wrapper.emitted('saved')).toBeUndefined()
      wrapper.unmount()
    })

    it('keeps the pending edit dirty when an acknowledged retry is refused again', async () => {
      confirmAdvisoryReviewMock.mockResolvedValue(false)
      const wrapper = await mountWorkspace()
      await wrapper.get('textarea').setValue('Reserved slug rewrite')
      hold()
      await nextTick()

      await wrapper.get('[data-page-builder-advisory-confirm]').trigger('click')
      await flushPromises()

      expect(confirmAdvisoryReviewMock).toHaveBeenCalledOnce()
      expect(wrapper.emitted('saved')).toBeUndefined()
      expect(wrapper.emitted('dirty')?.at(-1)).toEqual([true])
      expect(wrapper.get('textarea').element.value).toBe('Reserved slug rewrite')
      wrapper.unmount()
    })

    it('presents an unsupported deployment as a configuration problem with no confirm affordance', async () => {
      advisoryUnsupportedRef.value = 'This layout draft surface cannot accept save advisory acknowledgements.'
      const wrapper = await mountWorkspace()

      const banner = wrapper.get('[data-page-builder-advisory-unsupported]')
      expect(banner.attributes('role')).toBe('alert')
      expect(banner.text()).toContain('page_builder_advisory_unsupported_title')
      expect(banner.text()).toContain('page_builder_advisory_unsupported_help')
      expect(banner.findAll('button')).toHaveLength(0)
      expect(wrapper.find('[data-page-builder-advisory-confirm]').exists()).toBe(false)
      expect(wrapper.find('[data-page-builder-advisory]').exists()).toBe(false)
    })
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

  it('disables block insertion and explains when the document has no section', async () => {
    draftRef.value = structuredClone(draft)
    draftRef.value.document.sections = []
    const wrapper = await mountWorkspace()

    const paletteButton = wrapper.get('.page-builder__block-card')
    expect(paletteButton.attributes('disabled')).toBeDefined()
    expect(paletteButton.attributes('aria-describedby')).toBe('page-builder-block-insertion-unavailable')
    expect(wrapper.get('#page-builder-block-insertion-unavailable').text()).toBe('page_builder_block_insertion_unavailable')

    expect(applyMock).not.toHaveBeenCalled()
  })

  it('disables block insertion and explains when the fallback section has no region', async () => {
    draftRef.value = structuredClone(draft)
    draftRef.value.document.sections[0]!.regions = {}
    const wrapper = await mountWorkspace()

    const paletteButton = wrapper.get('.page-builder__block-card')
    expect(paletteButton.attributes('disabled')).toBeDefined()
    expect(paletteButton.attributes('aria-describedby')).toBe('page-builder-block-insertion-unavailable')
    expect(wrapper.get('#page-builder-block-insertion-unavailable').text()).toBe('page_builder_block_insertion_unavailable')

    expect(applyMock).not.toHaveBeenCalled()
  })

  it('keeps the selected dirty block and explicitly reports when its implicit save prevents insertion', async () => {
    const wrapper = await mountWorkspace()
    await wrapper.get('textarea').setValue('Pending edit that cannot be saved')
    applyMock.mockReset().mockResolvedValue(false)

    await wrapper.get('.page-builder__block-card').trigger('click')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledOnce()
    expect(applyMock).toHaveBeenCalledWith({
      type: 'configure_block',
      block_id: 'blk_intro',
      config: { body: 'Pending edit that cannot be saved' },
    })
    expect(wrapper.get('[data-block-select="blk_intro"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('textarea').element.value).toBe('Pending edit that cannot be saved')
    expect(wrapper.get('[data-page-builder-block-add-refusal]').text()).toBe('page_builder_block_not_added_save_failed')
    expect(wrapper.emitted('dirty')?.at(-1)).toEqual([true])
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('reports when the insertion target disappears while the implicit save is in flight', async () => {
    const wrapper = await mountWorkspace()
    await wrapper.get('textarea').setValue('Pending edit before a structural refresh')
    applyMock.mockReset().mockImplementation(async () => {
      const refreshed = structuredClone(draft)
      refreshed.document.sections = []
      draftRef.value = refreshed
      return true
    })

    await wrapper.get('.page-builder__block-card').trigger('click')
    await flushPromises()

    expect(applyMock).toHaveBeenCalledOnce()
    expect(applyMock.mock.calls[0]?.[0]).toEqual(expect.objectContaining({ type: 'configure_block' }))
    expect(wrapper.get('[data-page-builder-block-add-refusal]').text()).toBe('page_builder_block_insertion_unavailable')
    expect(wrapper.find('.page-builder__block-card').attributes('disabled')).toBeDefined()
  })

  it('clears a prior insertion refusal after the pending block later saves successfully', async () => {
    const wrapper = await mountWorkspace()
    await wrapper.get('textarea').setValue('Pending edit')
    applyMock.mockReset().mockResolvedValue(false)
    await wrapper.get('.page-builder__block-card').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-page-builder-block-add-refusal]').exists()).toBe(true)

    applyMock.mockResolvedValue(true)
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-page-builder-block-add-refusal]').exists()).toBe(false)
    expect(wrapper.text()).toContain('page_builder_saved')
  })

  it('offers only block and layout definitions allowed by the active template', async () => {
    const governedDefinitions = structuredClone(definitions)
    governedDefinitions.blocks.push({
      id: 'internal_only',
      version: 1,
      label: 'Internal only',
      renderer: 'content.internal',
      config_schema: { type: 'object', properties: {} },
    })
    governedDefinitions.layouts.push({
      id: 'campaign_only',
      version: 1,
      regions: ['main'],
      required_regions: ['main'],
      allowed_blocks: ['rich_text'],
    })
    definitionsRef.value = governedDefinitions

    const wrapper = await mountWorkspace()

    expect(wrapper.text()).not.toContain('Internal only')
    expect(wrapper.text()).not.toContain('Campaign only')
    expect(wrapper.findAll('.page-builder__block-card')).toHaveLength(1)
    expect(wrapper.findAll('.page-builder__add-section')).toHaveLength(1)
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
    definitionsRef.value.templates[0]!.allowed_layouts.push('sidebar')
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
    await wrapper.get('[data-block-select="blk_intro"]').trigger('click')
    await flushPromises()
    await wrapper.get('.btn-danger').trigger('click')
    await decideConfirmation()
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
    multiDefinitions.templates[0]!.allowed_layouts.push('two_column')
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

  it('flushes dirty block configuration before a structural move replaces the draft', async () => {
    const multiDefinitions = structuredClone(definitions)
    multiDefinitions.layouts.push({
      id: 'two_column',
      version: 1,
      regions: ['main', 'sidebar'],
      required_regions: ['main', 'sidebar'],
      allowed_blocks: ['rich_text'],
    })
    multiDefinitions.templates[0]!.allowed_layouts.push('two_column')
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

    await wrapper.get('textarea').setValue('Boozhoo — keep this')
    await wrapper.get('[data-block-destination]').setValue('sec_secondary::sidebar')
    await wrapper.get('[data-move-block-to-region]').trigger('click')
    await flushPromises()

    expect(applyMock.mock.calls.map(([command]) => command)).toEqual([
      { type: 'configure_block', block_id: 'blk_intro', config: { body: 'Boozhoo — keep this' } },
      {
        type: 'move_block',
        block_id: 'blk_intro',
        destination_section_id: 'sec_secondary',
        destination_region_id: 'sidebar',
        position: 0,
      },
    ])
    expect(wrapper.get('textarea').element.value).toBe('Boozhoo — keep this')
    expect(draftRef.value!.document.sections[1]!.regions.sidebar![0]!.config.body).toBe('Boozhoo — keep this')
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
    multiDefinitions.templates[0]!.allowed_layouts.push('two_column')
    definitionsRef.value = multiDefinitions
    const multiSectionDraft = structuredClone(draft)
    multiSectionDraft.document.sections.push({
      id: 'sec_secondary',
      layout: { id: 'one_column', version: 1 },
      regions: { main: [] },
    })
    draftRef.value = multiSectionDraft
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
    await flushPromises()
    expect(applyMock).toHaveBeenLastCalledWith({
      type: 'duplicate_section',
      source_section_id: 'sec_secondary',
      duplicate_section_id: 'sec_aaaaaaaabbbb4ccc8dddeeeeeeeeeeee',
      duplicate_block_ids: {},
    })

    await wrapper.get('[data-section-select="sec_main"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-duplicate-section]').trigger('click')
    await flushPromises()
    expect(applyMock).toHaveBeenLastCalledWith({
      type: 'duplicate_section',
      source_section_id: 'sec_main',
      duplicate_section_id: 'sec_11111111222243338444555555555555',
      duplicate_block_ids: { blk_intro: 'blk_99999999222243338444555555555555' },
    })
    expect((document.activeElement as HTMLElement).dataset.sectionSelect).toBe('sec_11111111222243338444555555555555')

    await wrapper.get('[data-section-select="sec_main"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-remove-section]').trigger('click')
    expect(document.querySelector('[role="alertdialog"]')?.textContent).toContain('page_builder_remove_section_confirm:1')
    await decideConfirmation()
    expect(applyMock).toHaveBeenLastCalledWith({ type: 'remove_section', section_id: 'sec_main' })
    expect((document.activeElement as HTMLElement).dataset.sectionSelect).toBe('sec_11111111222243338444555555555555')
    uuid.mockRestore()
  })

  it('does not remove a section when the destructive confirmation is declined', async () => {
    const twoSections = structuredClone(draft)
    twoSections.document.sections.push({
      id: 'sec_secondary',
      layout: { id: 'one_column', version: 1 },
      regions: { main: [] },
    })
    draftRef.value = twoSections
    const wrapper = await mountWorkspace()

    await wrapper.get('[data-section-select="sec_main"]').trigger('click')
    applyMock.mockClear()
    await wrapper.get('[data-remove-section]').trigger('click')
    await decideConfirmation(false)

    expect(applyMock).not.toHaveBeenCalled()
    expect(draftRef.value!.document.sections.map(section => section.id)).toEqual(['sec_main', 'sec_secondary'])
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
    expect(document.querySelector('[role="alertdialog"]')?.textContent).toContain('page_builder_restore_confirm:5')
    await decideConfirmation()
    await flushPromises()
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

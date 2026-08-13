import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import type { PageBuilderCommand, PageBuilderDraft } from '~/contracts/pageBuilder'

const mocks = vi.hoisted(() => ({
  definitions: vi.fn(),
  draft: vi.fn(),
  command: vi.fn(),
  preview: vi.fn(),
}))

vi.mock('~/runtime/pageBuilderClient', () => ({
  PageBuilderClient: class {
    definitions = mocks.definitions
    draft = mocks.draft
    command = mocks.command
    preview = mocks.preview
  },
}))

mockNuxtImport('useRuntimeConfig', () => () => ({ app: { baseURL: '/admin/' } }))
mockNuxtImport('useApi', () => () => ({ apiFetch: vi.fn() }))

const draft: PageBuilderDraft = {
  entity_id: '42',
  entity_revision_id: 7,
  document_fingerprint: 'a'.repeat(64),
  document: {
    schema: 'waaseyaa.layout',
    version: 1,
    template: { id: 'standard', version: 1 },
    sections: [],
  },
}

const definitions = { blocks: [], layouts: [], templates: [] }
const removeCommand: PageBuilderCommand = { type: 'remove_block', block_id: 'blk_intro' }

beforeEach(() => {
  vi.clearAllMocks()
  mocks.definitions.mockResolvedValue({ ok: true, data: { definitions } })
  mocks.draft.mockResolvedValue({ ok: true, data: draft })
  mocks.command.mockResolvedValue({ ok: true, data: { ...draft, entity_revision_id: 8 } })
  mocks.preview.mockResolvedValue({ ok: true, data: { preview_url: '/preview/42' } })
  vi.spyOn(crypto, 'randomUUID').mockReturnValue('11111111-1111-4111-8111-111111111111')
})

describe('usePageBuilder', () => {
  it('loads the definitions and exact working draft together', async () => {
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')

    await state.load()

    expect(state.loading.value).toBe(false)
    expect(state.error.value).toBeNull()
    expect(state.definitions.value).toEqual(definitions)
    expect(state.draft.value).toEqual(draft)
    expect(mocks.definitions).toHaveBeenCalledWith('page')
    expect(mocks.draft).toHaveBeenCalledWith('page', '42')
  })

  it('surfaces the server detail when loading is refused', async () => {
    mocks.definitions.mockResolvedValue({ ok: false, error: { title: 'Denied', detail: 'Page builder access denied' } })
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')

    await state.load()

    expect(state.error.value).toBe('Page builder access denied')
    expect(state.loading.value).toBe(false)
  })

  it('binds edits to the current draft and clears a stale preview', async () => {
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')
    await state.load()
    await state.refreshPreview()

    expect(state.previewUrl.value).toBe('/preview/42')
    await expect(state.apply(removeCommand)).resolves.toBe(true)
    expect(mocks.command).toHaveBeenCalledWith(
      'page',
      '42',
      draft,
      removeCommand,
      '11111111-1111-4111-8111-111111111111',
    )
    expect(state.draft.value?.entity_revision_id).toBe(8)
    expect(state.previewUrl.value).toBeNull()
    expect(state.saving.value).toBe(false)
  })

  it('refuses edit and preview work before a draft is loaded', async () => {
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')

    await expect(state.apply(removeCommand)).resolves.toBe(false)
    await expect(state.refreshPreview()).resolves.toBe(false)
    expect(mocks.command).not.toHaveBeenCalled()
    expect(mocks.preview).not.toHaveBeenCalled()
  })

  it('keeps the observed draft and exposes a failed edit detail', async () => {
    mocks.command.mockResolvedValue({ ok: false, error: { detail: 'Reload the newer page before editing.' } })
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')
    await state.load()

    await expect(state.apply(removeCommand)).resolves.toBe(false)
    expect(state.draft.value).toEqual(draft)
    expect(state.error.value).toBe('Reload the newer page before editing.')
    expect(state.saving.value).toBe(false)
  })

  it('loads an exact revision preview and reports preview refusal', async () => {
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')
    await state.load()

    await expect(state.refreshPreview()).resolves.toBe(true)
    expect(mocks.preview).toHaveBeenCalledWith('page', '42', 7)
    expect(state.previewUrl.value).toBe('/preview/42')

    mocks.preview.mockResolvedValue({ ok: false, error: { title: 'Preview unavailable' } })
    await expect(state.refreshPreview()).resolves.toBe(false)
    expect(state.error.value).toBe('Preview unavailable')
    expect(state.saving.value).toBe(false)
  })
})

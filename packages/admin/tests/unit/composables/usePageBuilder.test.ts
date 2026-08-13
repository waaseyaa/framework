import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'
import type { PageBuilderCommand, PageBuilderDraft, PageBuilderRevision } from '~/contracts/pageBuilder'

const mocks = vi.hoisted(() => ({
  definitions: vi.fn(),
  draft: vi.fn(),
  command: vi.fn(),
  preview: vi.fn(),
  history: vi.fn(),
  revision: vi.fn(),
  restore: vi.fn(),
}))

vi.mock('~/runtime/pageBuilderClient', () => ({
  PageBuilderClient: class {
    definitions = mocks.definitions
    draft = mocks.draft
    command = mocks.command
    preview = mocks.preview
    history = mocks.history
    revision = mocks.revision
    restore = mocks.restore
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
const history: PageBuilderRevision[] = [{
  revision_id: 7,
  created_at: '2026-08-13T10:00:00Z',
  author_id: 12,
  log: 'Updated landing page',
  is_current: true,
  is_latest: true,
  document_fingerprint: 'a'.repeat(64),
  block_count: 0,
}]

beforeEach(() => {
  vi.clearAllMocks()
  mocks.definitions.mockResolvedValue({ ok: true, data: { definitions } })
  mocks.draft.mockResolvedValue({ ok: true, data: draft })
  mocks.command.mockResolvedValue({ ok: true, data: { ...draft, entity_revision_id: 8 } })
  mocks.preview.mockResolvedValue({ ok: true, data: { preview_url: '/preview/42' } })
  mocks.history.mockResolvedValue({ ok: true, data: { revisions: history } })
  mocks.revision.mockResolvedValue({ ok: true, data: { ...draft, entity_revision_id: 5 } })
  mocks.restore.mockResolvedValue({ ok: true, data: { ...draft, entity_revision_id: 8 } })
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

  it('loads revision history and exposes a server refusal', async () => {
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')

    await expect(state.loadHistory()).resolves.toBe(true)
    expect(mocks.history).toHaveBeenCalledWith('page', '42')
    expect(state.revisions.value).toEqual(history)

    mocks.history.mockResolvedValue({ ok: false, error: { detail: 'History is unavailable.' } })
    await expect(state.loadHistory()).resolves.toBe(false)
    expect(state.error.value).toBe('History is unavailable.')
  })

  it('loads an exact historical revision for comparison and reports failure', async () => {
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')

    await expect(state.compareRevision(5)).resolves.toBe(true)
    expect(mocks.revision).toHaveBeenCalledWith('page', '42', 5)
    expect(state.comparedRevision.value?.entity_revision_id).toBe(5)

    mocks.revision.mockResolvedValue({ ok: false, error: { title: 'Revision not found' } })
    await expect(state.compareRevision(4)).resolves.toBe(false)
    expect(state.error.value).toBe('Revision not found')
  })

  it('restores history as a new conflict-guarded draft and refreshes the timeline', async () => {
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')
    await state.load()
    await state.compareRevision(5)
    await state.refreshPreview()

    await expect(state.restoreRevision(5)).resolves.toBe(true)
    expect(mocks.restore).toHaveBeenCalledWith(
      'page',
      '42',
      5,
      7,
      '11111111-1111-4111-8111-111111111111',
    )
    expect(state.draft.value?.entity_revision_id).toBe(8)
    expect(state.comparedRevision.value).toBeNull()
    expect(state.previewUrl.value).toBeNull()
    expect(mocks.history).toHaveBeenCalledWith('page', '42')
    expect(state.saving.value).toBe(false)
  })

  it('refuses restore without a loaded draft and preserves the observed draft on failure', async () => {
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')

    await expect(state.restoreRevision(5)).resolves.toBe(false)
    expect(mocks.restore).not.toHaveBeenCalled()

    await state.load()
    mocks.restore.mockResolvedValue({ ok: false, error: { detail: 'The page changed before restore.' } })
    await expect(state.restoreRevision(5)).resolves.toBe(false)
    expect(state.draft.value).toEqual(draft)
    expect(state.error.value).toBe('The page changed before restore.')
    expect(state.saving.value).toBe(false)
  })
})

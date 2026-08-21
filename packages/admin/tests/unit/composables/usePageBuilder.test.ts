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
    mocks.definitions.mockResolvedValue({ ok: false, error: { status: 403, title: 'Denied', detail: 'Page builder access denied' } })
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')

    await state.load()

    expect(state.error.value).toBe('Page builder access denied')
    expect(state.failure.value).toEqual({ kind: 'permission-denied', status: 403 })
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
      [],
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

  it('preserves a stale command, compares with the latest draft, and retries only after explicit choice', async () => {
    mocks.command.mockResolvedValueOnce({
      ok: false,
      error: { status: 409, title: 'Page changed', detail: 'Another editor saved first.' },
    })
    const latest = { ...draft, entity_revision_id: 9, document_fingerprint: 'b'.repeat(64) }
    const retried = { ...draft, entity_revision_id: 10, document_fingerprint: 'c'.repeat(64) }
    const { usePageBuilder } = await import('~/composables/usePageBuilder')
    const state = usePageBuilder('page', '42')
    await state.load()

    await expect(state.apply(removeCommand)).resolves.toBe(false)
    expect(state.error.value).toBeNull()
    expect(state.conflict.value?.command).toEqual(removeCommand)
    expect(state.conflict.value?.localDraft).toEqual(draft)
    expect(state.failure.value).toEqual({ kind: 'conflict', status: 409 })
    await expect(state.apply(removeCommand)).resolves.toBe(false)
    expect(mocks.command).toHaveBeenCalledTimes(1)

    mocks.draft.mockResolvedValueOnce({ ok: true, data: latest })
    await expect(state.loadLatestForConflict()).resolves.toBe(true)
    expect(state.draft.value).toEqual(latest)
    expect(state.comparedRevision.value).toEqual(draft)
    expect(state.conflict.value?.latestLoaded).toBe(true)

    mocks.command.mockResolvedValueOnce({ ok: true, data: retried })
    await expect(state.retryConflict()).resolves.toBe(true)
    expect(mocks.command).toHaveBeenLastCalledWith(
      'page',
      '42',
      latest,
      removeCommand,
      '11111111-1111-4111-8111-111111111111',
      [],
    )
    expect(state.draft.value).toEqual(retried)
    expect(state.conflict.value).toBeNull()
  })

  describe('layout save advisory review (#2475)', () => {
    const token = 'b'.repeat(64)
    const secondToken = 'c'.repeat(64)
    const advisory = {
      code: 'RESERVED_ROUTE_VALUE',
      field: 'slug',
      severity: 'warning' as const,
      message: 'This slug is reserved for a system route.',
      acknowledgement: token,
    }
    const heldResult = (acknowledgement = token) => ({
      ok: false,
      error: {
        status: 428,
        title: 'Precondition Required',
        detail: 'This change needs review before it can be saved.',
        code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
        meta: { save_advisories: [{ ...advisory, acknowledgement }] },
      },
    })

    async function heldState() {
      mocks.command.mockResolvedValueOnce(heldResult())
      const { usePageBuilder } = await import('~/composables/usePageBuilder')
      const state = usePageBuilder('page', '42')
      await state.load()
      await expect(state.apply(removeCommand)).resolves.toBe(false)
      return state
    }

    it('holds the exact pending edit for review and writes nothing', async () => {
      const state = await heldState()

      expect(state.advisoryReview.value?.advisories).toEqual([advisory])
      expect(state.advisoryReview.value?.command).toEqual(removeCommand)
      expect(state.advisoryReview.value?.detail).toBe('This change needs review before it can be saved.')
      expect(state.draft.value).toEqual(draft)
      expect(state.error.value).toBeNull()
      // A held edit is a review prompt, not an embed lifecycle failure.
      expect(state.failure.value).toBeNull()
      expect(state.advisoryUnsupported.value).toBeNull()
    })

    it('blocks a further ordinary edit while the review is open', async () => {
      const state = await heldState()

      await expect(state.apply({ type: 'remove_block', block_id: 'blk_other' })).resolves.toBe(false)
      expect(mocks.command).toHaveBeenCalledTimes(1)
    })

    it('returns exactly the received receipts on the same command, revision, and fingerprint', async () => {
      const state = await heldState()
      mocks.command.mockResolvedValueOnce({ ok: true, data: { ...draft, entity_revision_id: 8 } })

      await expect(state.confirmAdvisoryReview()).resolves.toBe(true)

      expect(mocks.command).toHaveBeenLastCalledWith(
        'page',
        '42',
        draft,
        removeCommand,
        '11111111-1111-4111-8111-111111111111',
        [token],
      )
      expect(state.advisoryReview.value).toBeNull()
      expect(state.draft.value?.entity_revision_id).toBe(8)
    })

    it('re-prompts with the new advisory when the candidate changed underneath, never replaying the stale receipt', async () => {
      const state = await heldState()
      mocks.command.mockResolvedValueOnce(heldResult(secondToken))

      await expect(state.confirmAdvisoryReview()).resolves.toBe(false)

      expect(mocks.command).toHaveBeenLastCalledWith(
        'page', '42', draft, removeCommand, '11111111-1111-4111-8111-111111111111', [token],
      )
      expect(state.advisoryReview.value?.advisories).toEqual([{ ...advisory, acknowledgement: secondToken }])

      mocks.command.mockResolvedValueOnce({ ok: true, data: { ...draft, entity_revision_id: 8 } })
      await expect(state.confirmAdvisoryReview()).resolves.toBe(true)
      expect(mocks.command).toHaveBeenLastCalledWith(
        'page', '42', draft, removeCommand, '11111111-1111-4111-8111-111111111111', [secondToken],
      )
    })

    it('leaves the draft unsaved and the review closed when the author declines', async () => {
      const state = await heldState()

      state.declineAdvisoryReview()

      expect(state.advisoryReview.value).toBeNull()
      expect(state.draft.value).toEqual(draft)
      expect(state.error.value).toBeNull()
      expect(mocks.command).toHaveBeenCalledTimes(1)
      await expect(state.confirmAdvisoryReview()).resolves.toBe(false)
      expect(mocks.command).toHaveBeenCalledTimes(1)
    })

    it('keeps a rejected receipt a refusal with no write and no reusable token', async () => {
      const state = await heldState()
      mocks.command.mockResolvedValueOnce({
        ok: false,
        error: { status: 422, title: 'Page layout is not editable' },
      })

      await expect(state.confirmAdvisoryReview()).resolves.toBe(false)

      expect(state.draft.value).toEqual(draft)
      expect(state.advisoryReview.value).toBeNull()
      expect(state.error.value).toBe('Page layout is not editable')
    })

    it('treats a malformed advisory projection as a refusal rather than a review', async () => {
      mocks.command.mockResolvedValueOnce({
        ok: false,
        error: {
          status: 428,
          title: 'Precondition Required',
          code: 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED',
          meta: { save_advisories: [{ ...advisory, acknowledgement: 'not-a-token' }] },
        },
      })
      const { usePageBuilder } = await import('~/composables/usePageBuilder')
      const state = usePageBuilder('page', '42')
      await state.load()

      await expect(state.apply(removeCommand)).resolves.toBe(false)

      expect(state.advisoryReview.value).toBeNull()
      expect(state.error.value).toBe('Precondition Required')
      expect(state.draft.value).toEqual(draft)
    })

    it('presents an unsupported deployment as a capability problem with no review to confirm', async () => {
      mocks.command.mockResolvedValueOnce({
        ok: false,
        error: {
          status: 501,
          title: 'Save advisory acknowledgement unsupported',
          detail: 'This layout draft surface cannot accept save advisory acknowledgements.',
          code: 'SAVE_ADVISORY_UNSUPPORTED',
        },
      })
      const { usePageBuilder } = await import('~/composables/usePageBuilder')
      const state = usePageBuilder('page', '42')
      await state.load()

      await expect(state.apply(removeCommand)).resolves.toBe(false)

      expect(state.advisoryUnsupported.value)
        .toBe('This layout draft surface cannot accept save advisory acknowledgements.')
      expect(state.advisoryReview.value).toBeNull()
      // Not an author-fixable validation error: no inline error, but a real failure.
      expect(state.error.value).toBeNull()
      expect(state.failure.value).toEqual({ kind: 'server', status: 501 })
      expect(state.draft.value).toEqual(draft)
    })

    it('clears a prior unsupported notice once another attempt is made', async () => {
      mocks.command.mockResolvedValueOnce({
        ok: false,
        error: { status: 501, title: 'Unsupported', code: 'SAVE_ADVISORY_UNSUPPORTED' },
      })
      const { usePageBuilder } = await import('~/composables/usePageBuilder')
      const state = usePageBuilder('page', '42')
      await state.load()
      await state.apply(removeCommand)
      expect(state.advisoryUnsupported.value).toBe('Unsupported')

      await expect(state.apply(removeCommand)).resolves.toBe(true)
      expect(state.advisoryUnsupported.value).toBeNull()
    })

    it('acknowledges a held edit that arrived on a conflict replay', async () => {
      mocks.command.mockResolvedValueOnce({
        ok: false,
        error: { status: 409, title: 'Page changed', detail: 'Another editor saved first.' },
      })
      const latest = { ...draft, entity_revision_id: 9, document_fingerprint: 'd'.repeat(64) }
      const { usePageBuilder } = await import('~/composables/usePageBuilder')
      const state = usePageBuilder('page', '42')
      await state.load()
      await expect(state.apply(removeCommand)).resolves.toBe(false)

      mocks.draft.mockResolvedValueOnce({ ok: true, data: latest })
      await expect(state.loadLatestForConflict()).resolves.toBe(true)

      mocks.command.mockResolvedValueOnce(heldResult())
      await expect(state.retryConflict()).resolves.toBe(false)
      expect(state.advisoryReview.value?.advisories).toEqual([advisory])

      mocks.command.mockResolvedValueOnce({ ok: true, data: { ...latest, entity_revision_id: 10 } })
      await expect(state.confirmAdvisoryReview()).resolves.toBe(true)
      expect(mocks.command).toHaveBeenLastCalledWith(
        'page', '42', latest, removeCommand, '11111111-1111-4111-8111-111111111111', [token],
      )
      expect(state.conflict.value).toBeNull()
    })
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

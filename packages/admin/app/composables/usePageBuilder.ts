import type { AdminSurfaceSaveAdvisory } from '../contracts/adminSurface'
import type { PageBuilderCommand, PageBuilderDefinitions, PageBuilderDraft, PageBuilderRevision } from '../contracts/pageBuilder'
import { PageBuilderClient } from '../runtime/pageBuilderClient'
import { normalizeAppBaseURL } from '../runtime/normalizeAppBaseURL'
import { classifyEmbedFailure, type EmbedFailure } from '../runtime/embedLifecycle'
import { isUnsupportedLayoutSaveAdvisory, layoutSaveAdvisoriesFrom } from '../runtime/layoutSaveAdvisory'

/**
 * A layout edit the server is holding for author review (#2475).
 *
 * `command` is the exact pending mutation, retained so the acknowledged retry
 * replays it against the same observed revision and document fingerprint.
 * `advisories` carry the candidate-bound receipts verbatim; they live only for
 * as long as this prompt and are never reused for another candidate.
 */
export interface PageBuilderAdvisoryReview {
  command: PageBuilderCommand
  advisories: AdminSurfaceSaveAdvisory[]
  detail: string
}

interface ApplyOptions {
  /** Replay a change that was refused by optimistic concurrency. */
  afterConflict?: boolean
  /** Replay the pending change with the receipts its own advisory issued. */
  afterAdvisory?: boolean
  acknowledgements?: string[]
}

function idempotencyKey(): string {
  if (typeof crypto === 'undefined' || typeof crypto.randomUUID !== 'function') {
    throw new Error('This browser cannot create a secure page-builder operation identifier.')
  }
  return crypto.randomUUID()
}

function cloneValue<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T
}

export function usePageBuilder(surface: string, entityId: string) {
  const definitions = ref<PageBuilderDefinitions | null>(null)
  const draft = ref<PageBuilderDraft | null>(null)
  const previewUrl = ref<string | null>(null)
  const revisions = ref<PageBuilderRevision[]>([])
  const comparedRevision = ref<PageBuilderDraft | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const failure = ref<EmbedFailure | null>(null)
  const conflict = ref<{
    command: PageBuilderCommand
    localDraft: PageBuilderDraft
    detail: string
    latestLoaded: boolean
  } | null>(null)
  const advisoryReview = ref<PageBuilderAdvisoryReview | null>(null)
  const advisoryUnsupported = ref<string | null>(null)
  // A held edit can arrive on a conflict replay. The acknowledged retry is the
  // same already-authorized mutation, so it must keep that authorization rather
  // than be refused by the conflict guard.
  let advisoryFollowsConflictRetry = false
  const config = useRuntimeConfig()
  const { apiFetch } = useApi()
  const client = new PageBuilderClient(
    normalizeAppBaseURL(String(config.app.baseURL || '/admin/')),
    apiFetch,
  )

  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    failure.value = null
    try {
      const [definitionResult, draftResult] = await Promise.all([
        client.definitions(surface),
        client.draft(surface, entityId),
      ])
      if (!definitionResult.ok || !definitionResult.data) {
        failure.value = classifyEmbedFailure(definitionResult.error)
        throw new Error(definitionResult.error?.detail || definitionResult.error?.title || 'Unable to load page-builder definitions.')
      }
      if (!draftResult.ok || !draftResult.data) {
        failure.value = classifyEmbedFailure(draftResult.error)
        throw new Error(draftResult.error?.detail || draftResult.error?.title || 'Unable to load the page draft.')
      }
      definitions.value = definitionResult.data.definitions
      draft.value = draftResult.data
    } catch (reason) {
      failure.value ??= classifyEmbedFailure(reason)
      error.value = reason instanceof Error ? reason.message : 'Unable to load the page builder.'
    } finally {
      loading.value = false
    }
  }

  async function performApply(command: PageBuilderCommand, options: ApplyOptions = {}): Promise<boolean> {
    if (!draft.value || saving.value) return false
    if (conflict.value && !options.afterConflict) return false
    if (advisoryReview.value && !options.afterAdvisory) return false
    saving.value = true
    error.value = null
    failure.value = null
    advisoryUnsupported.value = null
    try {
      const operationId = idempotencyKey()
      const result = await client.command(
        surface,
        entityId,
        draft.value,
        command,
        operationId,
        options.acknowledgements ?? [],
      )
      if (!result.ok && result.error?.status === 409) {
        failure.value = { kind: 'conflict', status: 409 }
        conflict.value = {
          command: cloneValue(command),
          localDraft: cloneValue(draft.value),
          detail: result.error.detail || result.error.title,
          latestLoaded: false,
        }
        return false
      }
      // A deployment whose layout gateway cannot carry receipts at all. This is
      // a capability problem the author cannot correct, so it is never offered
      // as a review to confirm.
      if (!result.ok && isUnsupportedLayoutSaveAdvisory(result.error)) {
        failure.value = classifyEmbedFailure(result.error)
        advisoryUnsupported.value = result.error?.detail
          || result.error?.title
          || 'This deployment cannot record an acknowledgement for this page.'
        return false
      }
      // A held edit. The server wrote nothing; the pending change stays dirty
      // in the workspace until an acknowledged retry actually succeeds.
      const advisories = layoutSaveAdvisoriesFrom(result.error)
      if (!result.ok && advisories !== null) {
        advisoryFollowsConflictRetry = options.afterConflict === true
        advisoryReview.value = {
          command: cloneValue(command),
          advisories,
          detail: result.error?.detail || result.error?.title || '',
        }
        return false
      }
      if (!result.ok || !result.data) {
        failure.value = classifyEmbedFailure(result.error)
        throw new Error(result.error?.detail || result.error?.title || 'Unable to save the page change.')
      }
      draft.value = result.data
      previewUrl.value = null
      conflict.value = null
      return true
    } catch (reason) {
      failure.value ??= classifyEmbedFailure(reason)
      error.value = reason instanceof Error ? reason.message : 'Unable to save the page change.'
      return false
    } finally {
      saving.value = false
    }
  }

  async function apply(command: PageBuilderCommand): Promise<boolean> {
    return performApply(command)
  }

  /**
   * Retry the exact held mutation with exactly the receipts that candidate's
   * own advisory carried.
   *
   * The review is dropped before the retry, so a second `428` — the candidate
   * moved underneath the author — installs the *new* advisory and its new
   * receipts rather than replaying a superseded one.
   */
  async function confirmAdvisoryReview(): Promise<boolean> {
    const pending = advisoryReview.value
    if (!pending || saving.value) return false
    const command = cloneValue(pending.command)
    const acknowledgements = pending.advisories.map(advisory => advisory.acknowledgement)
    advisoryReview.value = null
    return performApply(command, {
      afterAdvisory: true,
      afterConflict: advisoryFollowsConflictRetry,
      acknowledgements,
    })
  }

  /** Decline the review: nothing is written and the editor keeps its edit. */
  function declineAdvisoryReview(): void {
    advisoryReview.value = null
    advisoryFollowsConflictRetry = false
  }

  function dismissAdvisoryUnsupported(): void {
    advisoryUnsupported.value = null
  }

  async function loadLatestForConflict(): Promise<boolean> {
    if (!conflict.value || saving.value) return false
    saving.value = true
    error.value = null
    failure.value = null
    try {
      const result = await client.draft(surface, entityId)
      if (!result.ok || !result.data) {
        failure.value = classifyEmbedFailure(result.error)
        throw new Error(result.error?.detail || result.error?.title || 'Unable to load the newer page draft.')
      }
      comparedRevision.value = cloneValue(conflict.value.localDraft)
      draft.value = result.data
      previewUrl.value = null
      conflict.value.latestLoaded = true
      return true
    } catch (reason) {
      failure.value ??= classifyEmbedFailure(reason)
      error.value = reason instanceof Error ? reason.message : 'Unable to load the newer page draft.'
      return false
    } finally {
      saving.value = false
    }
  }

  async function retryConflict(): Promise<boolean> {
    if (!conflict.value?.latestLoaded) return false
    const command = cloneValue(conflict.value.command)
    return performApply(command, { afterConflict: true })
  }

  function dismissConflict(): void {
    conflict.value = null
    comparedRevision.value = null
  }

  async function refreshPreview(): Promise<boolean> {
    if (!draft.value || saving.value) return false
    saving.value = true
    error.value = null
    failure.value = null
    try {
      const result = await client.preview(surface, entityId, draft.value.entity_revision_id)
      if (!result.ok || !result.data) {
        failure.value = classifyEmbedFailure(result.error)
        throw new Error(result.error?.detail || result.error?.title || 'Unable to load the exact preview.')
      }
      previewUrl.value = result.data.preview_url
      return true
    } catch (reason) {
      failure.value ??= classifyEmbedFailure(reason)
      error.value = reason instanceof Error ? reason.message : 'Unable to load the exact preview.'
      return false
    } finally {
      saving.value = false
    }
  }

  async function loadHistory(): Promise<boolean> {
    error.value = null
    failure.value = null
    try {
      const result = await client.history(surface, entityId)
      if (!result.ok || !result.data) {
        failure.value = classifyEmbedFailure(result.error)
        throw new Error(result.error?.detail || result.error?.title || 'Unable to load revision history.')
      }
      revisions.value = result.data.revisions
      return true
    } catch (reason) {
      failure.value ??= classifyEmbedFailure(reason)
      error.value = reason instanceof Error ? reason.message : 'Unable to load revision history.'
      return false
    }
  }

  async function compareRevision(revisionId: number): Promise<boolean> {
    error.value = null
    failure.value = null
    try {
      const result = await client.revision(surface, entityId, revisionId)
      if (!result.ok || !result.data) {
        failure.value = classifyEmbedFailure(result.error)
        throw new Error(result.error?.detail || result.error?.title || 'Unable to load that revision.')
      }
      comparedRevision.value = result.data
      return true
    } catch (reason) {
      failure.value ??= classifyEmbedFailure(reason)
      error.value = reason instanceof Error ? reason.message : 'Unable to load that revision.'
      return false
    }
  }

  async function restoreRevision(revisionId: number): Promise<boolean> {
    if (!draft.value || saving.value) return false
    saving.value = true
    error.value = null
    failure.value = null
    try {
      const result = await client.restore(surface, entityId, revisionId, draft.value.entity_revision_id, idempotencyKey())
      if (!result.ok || !result.data) {
        failure.value = classifyEmbedFailure(result.error)
        throw new Error(result.error?.detail || result.error?.title || 'Unable to restore that revision.')
      }
      draft.value = result.data
      comparedRevision.value = null
      previewUrl.value = null
      await loadHistory()
      return true
    } catch (reason) {
      failure.value ??= classifyEmbedFailure(reason)
      error.value = reason instanceof Error ? reason.message : 'Unable to restore that revision.'
      return false
    } finally {
      saving.value = false
    }
  }

  return {
    definitions, draft, previewUrl, revisions, comparedRevision, loading, saving, error, failure, conflict,
    advisoryReview, advisoryUnsupported,
    load, apply, loadLatestForConflict, retryConflict, dismissConflict,
    confirmAdvisoryReview, declineAdvisoryReview, dismissAdvisoryUnsupported,
    refreshPreview, loadHistory, compareRevision, restoreRevision,
  }
}

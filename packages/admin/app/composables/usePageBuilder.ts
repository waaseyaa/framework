import type { PageBuilderCommand, PageBuilderDefinitions, PageBuilderDraft } from '../contracts/pageBuilder'
import { PageBuilderClient } from '../runtime/pageBuilderClient'
import { normalizeAppBaseURL } from '../runtime/normalizeAppBaseURL'

function idempotencyKey(): string {
  if (typeof crypto === 'undefined' || typeof crypto.randomUUID !== 'function') {
    throw new Error('This browser cannot create a secure page-builder operation identifier.')
  }
  return crypto.randomUUID()
}

export function usePageBuilder(surface: string, entityId: string) {
  const definitions = ref<PageBuilderDefinitions | null>(null)
  const draft = ref<PageBuilderDraft | null>(null)
  const previewUrl = ref<string | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const config = useRuntimeConfig()
  const { apiFetch } = useApi()
  const client = new PageBuilderClient(
    normalizeAppBaseURL(String(config.app.baseURL || '/admin/')),
    apiFetch,
  )

  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const [definitionResult, draftResult] = await Promise.all([
        client.definitions(surface),
        client.draft(surface, entityId),
      ])
      if (!definitionResult.ok || !definitionResult.data) throw new Error(definitionResult.error?.detail || definitionResult.error?.title || 'Unable to load page-builder definitions.')
      if (!draftResult.ok || !draftResult.data) throw new Error(draftResult.error?.detail || draftResult.error?.title || 'Unable to load the page draft.')
      definitions.value = definitionResult.data.definitions
      draft.value = draftResult.data
    } catch (reason) {
      error.value = reason instanceof Error ? reason.message : 'Unable to load the page builder.'
    } finally {
      loading.value = false
    }
  }

  async function apply(command: PageBuilderCommand): Promise<boolean> {
    if (!draft.value || saving.value) return false
    saving.value = true
    error.value = null
    try {
      const operationId = idempotencyKey()
      const result = await client.command(surface, entityId, draft.value, command, operationId)
      if (!result.ok || !result.data) throw new Error(result.error?.detail || result.error?.title || 'Unable to save the page change.')
      draft.value = result.data
      previewUrl.value = null
      return true
    } catch (reason) {
      error.value = reason instanceof Error ? reason.message : 'Unable to save the page change.'
      return false
    } finally {
      saving.value = false
    }
  }

  async function refreshPreview(): Promise<boolean> {
    if (!draft.value || saving.value) return false
    saving.value = true
    error.value = null
    try {
      const result = await client.preview(surface, entityId, draft.value.entity_revision_id)
      if (!result.ok || !result.data) throw new Error(result.error?.detail || result.error?.title || 'Unable to load the exact preview.')
      previewUrl.value = result.data.preview_url
      return true
    } catch (reason) {
      error.value = reason instanceof Error ? reason.message : 'Unable to load the exact preview.'
      return false
    } finally {
      saving.value = false
    }
  }

  return { definitions, draft, previewUrl, loading, saving, error, load, apply, refreshPreview }
}

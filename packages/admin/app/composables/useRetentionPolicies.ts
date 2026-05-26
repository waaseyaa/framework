// useRetentionPolicies — admin SPA composable for the classification
// retention-policy editor (classification-retention-engine-01KSEFTH WP04).
//
// Backs the /classification/policies list + detail pages. Reads and mutates
// the RetentionPolicy entity through the friendly JSON:API alias routes
// registered in BuiltinRouteRegistrar (api.classification.policies.*):
//   GET    /api/classification/policies        → index
//   GET    /api/classification/policies/{id}    → show
//   POST   /api/classification/policies        → store  (admin only)
//   PATCH  /api/classification/policies/{id}    → update (admin only)
//   DELETE /api/classification/policies/{id}    → destroy (admin only)
//
// Error handling mirrors useQueueJobs.ts (capture JSON:API error detail).

import { ref } from 'vue'
import { useApi } from '~/composables/useApi'

export type RetentionAction = 'purge' | 'redact' | 'hold-flag'
export type RetentionTriggerKind = 'age_based' | 'event_based'

/**
 * Operator-facing shape of a retention policy. Mirrors the RetentionPolicy
 * entity attributes (packages/field/src/Entity/RetentionPolicy.php).
 */
export interface RetentionPolicy {
  id: string
  name: string
  applies_to: string[]
  action: RetentionAction | string
  trigger_kind: RetentionTriggerKind | string
  trigger_value: string
  exemptions: string[]
  created_at?: string
}

/** JSON:API resource object as returned by the framework controller. */
interface JsonApiResource {
  type: string
  id: string | number
  attributes: Record<string, unknown>
}

interface JsonApiListDocument {
  data?: JsonApiResource[]
}

interface JsonApiSingleDocument {
  data?: JsonApiResource
}

const BASE = '/api/classification/policies'

function toStringArray(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.filter((v): v is string => typeof v === 'string')
  }
  if (typeof value === 'string' && value !== '') {
    try {
      const decoded: unknown = JSON.parse(value)
      return Array.isArray(decoded) ? decoded.filter((v): v is string => typeof v === 'string') : []
    } catch {
      return []
    }
  }
  return []
}

function hydrate(resource: JsonApiResource): RetentionPolicy {
  const attrs = resource.attributes ?? {}
  return {
    id: String(resource.id),
    name: typeof attrs.name === 'string' ? attrs.name : '',
    applies_to: toStringArray(attrs.applies_to),
    action: typeof attrs.action === 'string' ? attrs.action : '',
    trigger_kind: typeof attrs.trigger_kind === 'string' ? attrs.trigger_kind : '',
    trigger_value: typeof attrs.trigger_value === 'string' ? attrs.trigger_value : '',
    exemptions: toStringArray(attrs.exemptions),
    created_at: typeof attrs.created_at === 'string' ? attrs.created_at : undefined,
  }
}

function extractError(e: unknown, fallback: string): string {
  const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
  return err?.data?.errors?.[0]?.detail ?? err?.message ?? fallback
}

export function useRetentionPolicies() {
  const { apiFetch } = useApi()
  const policies = ref<RetentionPolicy[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchPolicies(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<JsonApiListDocument>(BASE)
      policies.value = (response.data ?? []).map(hydrate)
    } catch (e: unknown) {
      error.value = extractError(e, 'Failed to load retention policies.')
    } finally {
      loading.value = false
    }
  }

  /**
   * Create (no id) or update (with id) a policy. Returns the persisted policy,
   * or null when the request failed (error state is set).
   */
  async function savePolicy(policy: RetentionPolicy): Promise<RetentionPolicy | null> {
    error.value = null
    const attributes = {
      name: policy.name,
      applies_to: policy.applies_to,
      action: policy.action,
      trigger_kind: policy.trigger_kind,
      trigger_value: policy.trigger_value,
      exemptions: policy.exemptions,
    }
    const isUpdate = policy.id !== '' && policy.id !== 'new'
    const path = isUpdate ? `${BASE}/${policy.id}` : BASE
    const body = {
      data: {
        type: 'retention_policy',
        ...(isUpdate ? { id: policy.id } : {}),
        attributes,
      },
    }
    try {
      const response = await apiFetch<JsonApiSingleDocument>(path, {
        method: isUpdate ? 'PATCH' : 'POST',
        body,
      })
      return response.data !== undefined ? hydrate(response.data) : null
    } catch (e: unknown) {
      error.value = extractError(e, 'Failed to save retention policy.')
      return null
    }
  }

  async function deletePolicy(id: string): Promise<boolean> {
    error.value = null
    try {
      await apiFetch<unknown>(`${BASE}/${id}`, { method: 'DELETE' })
      policies.value = policies.value.filter(p => p.id !== id)
      return true
    } catch (e: unknown) {
      error.value = extractError(e, 'Failed to delete retention policy.')
      return false
    }
  }

  return { policies, loading, error, fetchPolicies, savePolicy, deletePolicy }
}

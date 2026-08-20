// useWorkflowTransitions — admin SPA composable for the CW-v1 transition UI
// (WP-4 Task C, #1920). Reads GET /api/{entityType}/{id}/workflow/transitions
// (the sanctioned UI read side — group- and permission-filtered, per WP-2/WP-3)
// and posts /api/{entityType}/{id}/workflow/transition. Modeled on
// useWorkflowDefinitions.ts: always goes through useApi().apiFetch, never raw
// $fetch, so the canonical root /api base is independent of the admin mount.

export interface WorkflowTransitionItem {
  id: string
  label: string
  to: string
}

export interface WorkflowTransitionHistoryItem {
  transition: string
  from: string
  to: string
  uid: string
  at: string
}

interface WorkflowTransitionsResponse {
  data: WorkflowTransitionItem[]
  meta?: {
    workflow_state?: string | null
    workflow_history?: WorkflowTransitionHistoryItem[]
  }
}

export interface WorkflowTransitionApplyResult {
  transition: string
  from: string
  to: string
  public_changed: boolean
}

interface WorkflowTransitionApplyResponse {
  data: WorkflowTransitionApplyResult
}

export type WorkflowTransitionErrorKind =
  | 'forbidden'
  | 'not_found'
  | 'malformed_response'
  | 'network'
  | 'server'

export function useWorkflowTransitions() {
  const { apiFetch } = useApi()

  const transitions = ref<WorkflowTransitionItem[]>([])
  const state = ref<string | null>(null)
  const history = ref<WorkflowTransitionHistoryItem[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const errorKind = ref<WorkflowTransitionErrorKind | null>(null)

  async function fetchTransitions(
    entityType: string,
    id: string,
  ): Promise<{ transitions: WorkflowTransitionItem[]; state: string | null; history: WorkflowTransitionHistoryItem[] }> {
    loading.value = true
    error.value = null
    errorKind.value = null
    try {
      const response = await apiFetch<WorkflowTransitionsResponse>(
        `/api/${entityType}/${encodeURIComponent(id)}/workflow/transitions`,
      )

      if (!isWorkflowTransitionsResponse(response)) {
        throw new WorkflowTransitionResponseError()
      }

      transitions.value = response.data
      state.value = response.meta?.workflow_state ?? null
      history.value = response.meta?.workflow_history ?? []
    } catch (e: unknown) {
      transitions.value = []
      state.value = null
      history.value = []

      if (e instanceof WorkflowTransitionResponseError) {
        errorKind.value = 'malformed_response'
        error.value = 'Workflow discovery returned an invalid response.'
        return { transitions: transitions.value, state: state.value, history: history.value }
      }

      const err = e as { data?: unknown; message?: string; response?: { status?: number }; statusCode?: number; cause?: unknown }
      const statusCode = err?.response?.status ?? err?.statusCode ?? 0
      if (statusCode === 404) {
        // Entity missing or not viewable — the canonical "no transitions" shape.
        // Render nothing, not an error (R8 oracle standard — see WP-4 plan).
        errorKind.value = 'not_found'
      } else {
        errorKind.value = classifyWorkflowTransitionError(err, statusCode)
        error.value = jsonApiErrorDetail(err?.data) ?? err?.message ?? 'Failed to load workflow transitions.'
      }
    } finally {
      loading.value = false
    }
    return { transitions: transitions.value, state: state.value, history: history.value }
  }

  async function applyTransition(
    entityType: string,
    id: string,
    transitionId: string,
  ): Promise<WorkflowTransitionApplyResult> {
    const response = await apiFetch<WorkflowTransitionApplyResponse>(
      `/api/${entityType}/${encodeURIComponent(id)}/workflow/transition`,
      { method: 'POST', body: { transition: transitionId } },
    )
    if (!isWorkflowTransitionApplyResponse(response)) {
      throw new WorkflowTransitionResponseError()
    }
    return response.data
  }

  return { transitions, state, history, loading, error, errorKind, fetchTransitions, applyTransition }
}

function isWorkflowTransitionApplyResponse(value: unknown): value is WorkflowTransitionApplyResponse {
  if (typeof value !== 'object' || value === null) return false
  const data = (value as { data?: unknown }).data
  return typeof data === 'object'
    && data !== null
    && typeof (data as WorkflowTransitionApplyResult).transition === 'string'
    && typeof (data as WorkflowTransitionApplyResult).from === 'string'
    && typeof (data as WorkflowTransitionApplyResult).to === 'string'
    && typeof (data as WorkflowTransitionApplyResult).public_changed === 'boolean'
}

class WorkflowTransitionResponseError extends Error {}

function isWorkflowTransitionsResponse(value: unknown): value is WorkflowTransitionsResponse {
  if (typeof value !== 'object' || value === null || !Array.isArray((value as WorkflowTransitionsResponse).data)) {
    return false
  }

  const response = value as WorkflowTransitionsResponse
  const validTransitions = response.data.every((transition) =>
    typeof transition === 'object'
    && transition !== null
    && typeof transition.id === 'string'
    && typeof transition.label === 'string'
    && typeof transition.to === 'string',
  )
  const history = response.meta?.workflow_history
  const validHistory = history === undefined || (Array.isArray(history) && history.every((entry) =>
    typeof entry === 'object'
    && entry !== null
    && typeof entry.transition === 'string'
    && typeof entry.from === 'string'
    && typeof entry.to === 'string'
    && typeof entry.uid === 'string'
    && typeof entry.at === 'string'
  ))

  return validTransitions && validHistory
}

function jsonApiErrorDetail(value: unknown): string | null {
  if (typeof value !== 'object' || value === null) return null

  const directErrors = (value as { errors?: unknown }).errors
  if (Array.isArray(directErrors) && typeof directErrors[0]?.detail === 'string') {
    return directErrors[0].detail
  }

  return jsonApiErrorDetail((value as { data?: unknown }).data)
}

function hasTypeErrorCause(value: unknown): boolean {
  let current = value
  const seen = new Set<unknown>()

  while (typeof current === 'object' && current !== null && !seen.has(current)) {
    if (current instanceof TypeError) return true
    seen.add(current)
    current = (current as { cause?: unknown }).cause
  }

  return false
}

export function classifyWorkflowTransitionError(
  error: unknown,
  statusCode: number,
): Exclude<WorkflowTransitionErrorKind, 'not_found' | 'malformed_response'> {
  if (statusCode === 403) return 'forbidden'
  if (statusCode === 0 || hasTypeErrorCause(error)) return 'network'
  return 'server'
}

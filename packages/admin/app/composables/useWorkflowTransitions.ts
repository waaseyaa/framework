// useWorkflowTransitions — admin SPA composable for the CW-v1 transition UI
// (WP-4 Task C, #1920). Reads GET /api/{entityType}/{id}/workflow/transitions
// (the sanctioned UI read side — group- and permission-filtered, per WP-2/WP-3)
// and posts /api/{entityType}/{id}/workflow/transition. Modeled on
// useWorkflowDefinitions.ts: always goes through useApi().apiFetch, never raw
// $fetch, so the admin subpath baseURL is respected.

export interface WorkflowTransitionItem {
  id: string
  label: string
  to: string
}

interface WorkflowTransitionsResponse {
  data: WorkflowTransitionItem[]
  meta?: { workflow_state?: string | null }
}

export interface WorkflowTransitionApplyResult {
  transition: string
  from: string
  to: string
}

interface WorkflowTransitionApplyResponse {
  data: WorkflowTransitionApplyResult
}

export function useWorkflowTransitions() {
  const { apiFetch } = useApi()

  const transitions = ref<WorkflowTransitionItem[]>([])
  const state = ref<string | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTransitions(
    entityType: string,
    id: string,
  ): Promise<{ transitions: WorkflowTransitionItem[]; state: string | null }> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<WorkflowTransitionsResponse>(
        `/api/${entityType}/${encodeURIComponent(id)}/workflow/transitions`,
      )
      transitions.value = response.data ?? []
      state.value = response.meta?.workflow_state ?? null
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }; message?: string; response?: { status?: number }; statusCode?: number }
      const statusCode = err?.response?.status ?? err?.statusCode ?? 0
      if (statusCode === 404) {
        // Entity missing or not viewable — the canonical "no transitions" shape.
        // Render nothing, not an error (R8 oracle standard — see WP-4 plan).
        transitions.value = []
        state.value = null
      } else {
        error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load workflow transitions.'
      }
    } finally {
      loading.value = false
    }
    return { transitions: transitions.value, state: state.value }
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
    return response.data
  }

  return { transitions, state, loading, error, fetchTransitions, applyTransition }
}

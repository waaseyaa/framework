// useWorkflowGuards — admin SPA composable for the read-only workflow guards
// matrix surface (M4A-5 Phase 1, mission `workflow-guards-readonly-01KSDS5W`,
// parent #1470).
//
// Fetches `GET /api/workflow-definitions/{workflow_id}/guards` and exposes the
// flattened (bundle, transition, required_roles) rows. Mirrors
// `useQueueJobs.ts` (M4B WP01) and `useWorkflowDefinitions.ts` (M4A-1) for
// shape and naming.
//
// Phase 2 (edit) is deferred — see follow-up M4A-5b.

export interface WorkflowGuardRow {
  bundle: string
  transition: string
  required_roles: string[]
}

export interface WorkflowGuardsResponse {
  data: WorkflowGuardRow[]
}

export function useWorkflowGuards() {
  const { apiFetch } = useApi()
  const guards = ref<WorkflowGuardRow[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchGuards(workflowId: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<WorkflowGuardsResponse>(
        `/api/workflow-definitions/${encodeURIComponent(workflowId)}/guards`,
      )
      guards.value = response.data ?? []
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load workflow guards.'
      guards.value = []
    } finally {
      loading.value = false
    }
  }

  return { guards, loading, error, fetchGuards }
}

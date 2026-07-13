export interface WorkflowState {
  id: string
  label: string
  weight: number
  metadata: Record<string, unknown>
}

export interface WorkflowTransition {
  id: string
  label: string
  from: string[]
  to: string
  weight: number
}

export interface WorkflowDefinition {
  id: string
  label: string
  states: WorkflowState[]
  transitions: WorkflowTransition[]
}

export function useWorkflowDefinitions() {
  const { apiFetch } = useApi()
  const workflows = ref<WorkflowDefinition[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchWorkflows() {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<{ data: WorkflowDefinition[] }>('/api/workflow-definitions')
      workflows.value = response.data ?? []
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load workflow definitions.'
    } finally {
      loading.value = false
    }
  }

  function findById(id: string): WorkflowDefinition | null {
    return workflows.value.find(w => w.id === id) ?? null
  }

  return { workflows, loading, error, fetchWorkflows, findById }
}

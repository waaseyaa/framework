// useScheduledTasks — admin SPA composable for the scheduler dashboard (M4B WP02).
//
// Fetches `GET /api/scheduler/tasks` and exposes a manual `triggerTask`
// action. Mirrors `useQueueJobs` (M4B WP01) for shape and naming.

export interface ScheduledTask {
  name: string
  description: string | null
  expression: string
  timezone: string | null
  last_run_at: string | null
  last_status: string | null
  next_run_at: string
}

export interface ScheduledTasksResponse {
  data: ScheduledTask[]
}

export interface TaskTriggerResult {
  status: 'success' | 'failed' | 'skipped: overlap' | null
  message: string | null
  exception_class?: string
}

export function useScheduledTasks() {
  const { apiFetch } = useApi()
  const tasks = ref<ScheduledTask[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTasks(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<ScheduledTasksResponse>('/api/scheduler/tasks')
      tasks.value = response.data ?? []
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load scheduled tasks.'
    } finally {
      loading.value = false
    }
  }

  async function triggerTask(name: string): Promise<TaskTriggerResult> {
    error.value = null
    const idempotencyKey = crypto.randomUUID()
    try {
      const result = await apiFetch<TaskTriggerResult>(
        `/api/scheduler/tasks/${encodeURIComponent(name)}/trigger`,
        { method: 'POST', headers: { 'Idempotency-Key': idempotencyKey } },
      )
      await fetchTasks()

      return result
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to trigger task.'
      throw e
    }
  }

  return { tasks, loading, error, fetchTasks, triggerTask }
}

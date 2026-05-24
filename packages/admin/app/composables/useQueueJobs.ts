// useQueueJobs — admin SPA composable for the failed-jobs queue dashboard (M4B WP01).
//
// Fetches `GET /api/queue/jobs` and exposes retry / discard actions. Mirrors
// `useWorkflowDefinitions` (M4A-1, PR #1429) for shape and naming.

export interface FailedJob {
  id: string
  queue: string
  payload: string
  payload_truncated: boolean
  exception_class: string
  exception_message: string
  failed_at: string
  attempts: number
}

export interface FailedJobMeta {
  page: number
  per_page: number
  total: number
}

export interface FailedJobsResponse {
  data: FailedJob[]
  meta: FailedJobMeta
}

export function useQueueJobs() {
  const { apiFetch } = useApi()
  const jobs = ref<FailedJob[]>([])
  const meta = ref<FailedJobMeta>({ page: 1, per_page: 20, total: 0 })
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchJobs(page = 1, perPage = 20): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<FailedJobsResponse>(
        `/api/queue/jobs?page=${page}&per_page=${perPage}`,
      )
      jobs.value = response.data ?? []
      meta.value = response.meta ?? { page, per_page: perPage, total: 0 }
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load failed jobs.'
    } finally {
      loading.value = false
    }
  }

  async function retryJob(id: string): Promise<void> {
    error.value = null
    try {
      await apiFetch<unknown>(`/api/queue/jobs/${encodeURIComponent(id)}/retry`, {
        method: 'POST',
      })
      await fetchJobs(meta.value.page, meta.value.per_page)
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to retry job.'
      throw e
    }
  }

  async function discardJob(id: string): Promise<void> {
    error.value = null
    try {
      await apiFetch<unknown>(`/api/queue/jobs/${encodeURIComponent(id)}/discard`, {
        method: 'POST',
      })
      await fetchJobs(meta.value.page, meta.value.per_page)
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to discard job.'
      throw e
    }
  }

  return { jobs, meta, loading, error, fetchJobs, retryJob, discardJob }
}

// useQueueJobs — admin SPA composable for the queue dashboard.
//
// M4B WP01 (PR #1471) shipped failed-only listing; #1576 (this WP) added
// `TransportInterface::listJobs()` so the composable can now fetch queued
// and in-flight jobs from the live transport. The optional `status` arg
// drives a `?status=...` query param: failed (default, backward-compatible),
// queued, in_progress, or all.

export type QueueStatusFilter = 'failed' | 'queued' | 'in_progress' | 'all'

/**
 * Row shape for failed jobs (M4B). Includes exception detail.
 */
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

/**
 * Row shape for live transport jobs (queued / in_progress). No exception
 * detail; has timing fields and a derived status.
 */
export interface TransportJob {
  id: string
  queue: string
  payload: string
  payload_truncated: boolean
  attempts: number
  available_at: number
  reserved_at: number | null
  status: 'queued' | 'in_progress'
}

/**
 * Union — a row can be either shape. Discriminate on the `status` field
 * (transport rows) or the presence of `exception_class` (failed rows).
 */
export type QueueJob = FailedJob | TransportJob

export interface FailedJobMeta {
  page: number
  per_page: number
  total: number
}

export interface FailedJobsResponse {
  data: QueueJob[]
  meta: FailedJobMeta
}

export function isFailedJob(job: QueueJob): job is FailedJob {
  return 'exception_class' in job
}

export function useQueueJobs() {
  const { apiFetch } = useApi()
  const jobs = ref<QueueJob[]>([])
  const meta = ref<FailedJobMeta>({ page: 1, per_page: 20, total: 0 })
  const loading = ref(false)
  const error = ref<string | null>(null)
  const status = ref<QueueStatusFilter>('failed')

  async function fetchJobs(
    page = 1,
    perPage = 20,
    statusFilter: QueueStatusFilter = 'failed',
  ): Promise<void> {
    loading.value = true
    error.value = null
    status.value = statusFilter
    try {
      const response = await apiFetch<FailedJobsResponse>(
        `/api/queue/jobs?page=${page}&per_page=${perPage}&status=${statusFilter}`,
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
      await fetchJobs(meta.value.page, meta.value.per_page, status.value)
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
      await fetchJobs(meta.value.page, meta.value.per_page, status.value)
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to discard job.'
      throw e
    }
  }

  return { jobs, meta, loading, error, status, fetchJobs, retryJob, discardJob }
}

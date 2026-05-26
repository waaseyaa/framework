// useAiObservabilityRunDetail — admin SPA composable for per-run detail + replay.
//
// M5B WP02 (#1415): consumes GET /api/ai/observability/runs/{uuid} and
// POST /api/ai/observability/runs/{uuid}/replay.

export interface RunSpanNode {
  spanUuid: string
  parentSpanUuid: string | null
  kind: string
  name: string
  startedAt: string
  endedAt: string | null
  status: string
  attributes: Record<string, unknown>
  children: RunSpanNode[]
  truncated: boolean
}

export interface RunDetailHeader {
  traceUuid: string
  pipeline: string
  status: string
  startedAt: string
  endedAt: string | null
  durationMs: number | null
  costUsd: number
  totalTokens: number
  spanCount: number
}

export interface RunDetail {
  header: RunDetailHeader
  spans: RunSpanNode[]
}

export interface RunReplayResult {
  newRunUuid: string
  status: string
  startedAt: string
}

interface RunDetailResponse {
  data: RunDetail
}

interface ReplayResponse {
  data: RunReplayResult
}

export function useAiObservabilityRunDetail() {
  const { apiFetch } = useApi()

  const run = ref<RunDetail | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchRun(uuid: string): Promise<void> {
    loading.value = true
    error.value = null
    run.value = null
    try {
      const response = await apiFetch<RunDetailResponse>(
        `/api/ai/observability/runs/${encodeURIComponent(uuid)}`,
      )
      run.value = response.data ?? null
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load run.'
    } finally {
      loading.value = false
    }
  }

  async function replay(uuid: string): Promise<RunReplayResult> {
    error.value = null
    try {
      const response = await apiFetch<ReplayResponse>(
        `/api/ai/observability/runs/${encodeURIComponent(uuid)}/replay`,
        { method: 'POST' },
      )
      return response.data
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to replay run.'
      throw e
    }
  }

  return { run, loading, error, fetchRun, replay }
}

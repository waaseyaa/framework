// useAiObservabilityRuns — admin SPA composable for the AI observability runs list.
//
// M5B WP02 (#1415): consumes GET /api/ai/observability/runs with optional
// pipeline/status/from/to filters and page/per_page pagination.

export interface RunListRow {
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

export interface RunListPage {
  rows: RunListRow[]
  total: number
  page: number
  perPage: number
}

export interface RunListFilter {
  pipeline?: string | null
  status?: string | null
  from?: string | null
  to?: string | null
}

interface RunListResponse {
  data: RunListPage
}

export function useAiObservabilityRuns() {
  const { apiFetch } = useApi()

  const rows = ref<RunListRow[]>([])
  const total = ref(0)
  const page = ref(1)
  const perPage = ref(25)
  const filter = ref<RunListFilter>({})
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchRuns(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const params = new URLSearchParams()
      params.set('page', String(page.value))
      params.set('per_page', String(perPage.value))
      if (filter.value.pipeline) {
        params.set('pipeline', filter.value.pipeline)
      }
      if (filter.value.status) {
        params.set('status', filter.value.status)
      }
      if (filter.value.from) {
        params.set('from', filter.value.from)
      }
      if (filter.value.to) {
        params.set('to', filter.value.to)
      }
      const response = await apiFetch<RunListResponse>(
        `/api/ai/observability/runs?${params.toString()}`,
      )
      rows.value = response.data?.rows ?? []
      total.value = response.data?.total ?? 0
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load runs.'
    } finally {
      loading.value = false
    }
  }

  function setFilter(partial: RunListFilter): void {
    filter.value = { ...filter.value, ...partial }
    page.value = 1
  }

  function setPage(n: number): void {
    page.value = n
  }

  return { rows, total, page, perPage, filter, loading, error, fetchRuns, setFilter, setPage }
}

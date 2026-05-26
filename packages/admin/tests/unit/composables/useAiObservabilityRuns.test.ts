// packages/admin/tests/unit/composables/useAiObservabilityRuns.test.ts
// useAiObservabilityRuns fetches GET /api/ai/observability/runs with filters + pagination.
// Mirrors useQueueJobs.test.ts (M4B, PR #1576).
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const seedRow = {
  traceUuid: 'trace-1',
  pipeline: 'my-pipeline',
  status: 'ok',
  startedAt: '2026-01-01T10:00:00+00:00',
  endedAt: '2026-01-01T10:00:05+00:00',
  durationMs: 5000,
  costUsd: 0.05,
  totalTokens: 300,
  spanCount: 2,
}

let storedRows: typeof seedRow[] = []
let lastFetchedUrl: string | null = null

registerEndpoint('/admin/api/ai/observability/runs', (event: unknown) => {
  const e = event as { node?: { req?: { url?: string } } }
  lastFetchedUrl = e.node?.req?.url ?? ''

  return {
    data: {
      rows: storedRows,
      total: storedRows.length,
      page: 1,
      perPage: 25,
    },
  }
})

beforeEach(() => {
  vi.resetModules()
  storedRows = [{ ...seedRow }]
  lastFetchedUrl = null
})

describe('useAiObservabilityRuns', () => {
  it('starts empty with no error and not loading', async () => {
    const { useAiObservabilityRuns } = await import('~/composables/useAiObservabilityRuns')
    const { rows, loading, error } = useAiObservabilityRuns()
    expect(rows.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates rows from /api/ai/observability/runs', async () => {
    const { useAiObservabilityRuns } = await import('~/composables/useAiObservabilityRuns')
    const { rows, total, loading, error, fetchRuns } = useAiObservabilityRuns()
    await fetchRuns()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(rows.value).toHaveLength(1)
    expect(rows.value[0].traceUuid).toBe('trace-1')
    expect(rows.value[0].pipeline).toBe('my-pipeline')
    expect(total.value).toBe(1)
  })

  it('sends page and per_page query params', async () => {
    const { useAiObservabilityRuns } = await import('~/composables/useAiObservabilityRuns')
    const { fetchRuns, setPage } = useAiObservabilityRuns()
    setPage(2)
    await fetchRuns()
    expect(lastFetchedUrl).toContain('page=2')
    expect(lastFetchedUrl).toContain('per_page=25')
  })

  it('sends pipeline filter when set', async () => {
    const { useAiObservabilityRuns } = await import('~/composables/useAiObservabilityRuns')
    const { fetchRuns, setFilter } = useAiObservabilityRuns()
    setFilter({ pipeline: 'my-pipeline' })
    await fetchRuns()
    expect(lastFetchedUrl).toContain('pipeline=my-pipeline')
  })

  it('sends status filter when set', async () => {
    const { useAiObservabilityRuns } = await import('~/composables/useAiObservabilityRuns')
    const { fetchRuns, setFilter } = useAiObservabilityRuns()
    setFilter({ status: 'error' })
    await fetchRuns()
    expect(lastFetchedUrl).toContain('status=error')
  })

  it('resets page to 1 when setFilter is called', async () => {
    const { useAiObservabilityRuns } = await import('~/composables/useAiObservabilityRuns')
    const { page, setPage, setFilter } = useAiObservabilityRuns()
    setPage(3)
    expect(page.value).toBe(3)
    setFilter({ pipeline: 'x' })
    expect(page.value).toBe(1)
  })

  it('handles empty rows gracefully', async () => {
    storedRows = []
    const { useAiObservabilityRuns } = await import('~/composables/useAiObservabilityRuns')
    const { rows, total, fetchRuns } = useAiObservabilityRuns()
    await fetchRuns()
    expect(rows.value).toEqual([])
    expect(total.value).toBe(0)
  })
})

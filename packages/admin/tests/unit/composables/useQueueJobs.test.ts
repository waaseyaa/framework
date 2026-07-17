// packages/admin/tests/unit/composables/useQueueJobs.test.ts
// useQueueJobs fetches GET /api/queue/jobs and exposes retry / discard actions.
// Mirrors useWorkflowDefinitions.test.ts (M4A-1, PR #1429).
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const seed = {
  id: '1',
  queue: 'default',
  payload: 'serialized-payload',
  payload_truncated: false,
  exception_class: 'RuntimeException',
  exception_message: 'boom',
  failed_at: '2026-05-24T00:00:00+00:00',
  attempts: 0,
}

// Live-transport row seeds (status = 'queued' | 'in_progress'). No
// exception fields — different shape from failed rows.
const queuedSeed = {
  id: '10',
  queue: 'default',
  payload: 'queued-payload',
  payload_truncated: false,
  attempts: 0,
  available_at: 1_700_000_000,
  reserved_at: null,
  status: 'queued' as const,
}

const inProgressSeed = {
  id: '11',
  queue: 'default',
  payload: 'reserved-payload',
  payload_truncated: false,
  attempts: 0,
  available_at: 1_700_000_000,
  reserved_at: 1_700_000_500,
  status: 'in_progress' as const,
}

type AnyJob = typeof seed | typeof queuedSeed | typeof inProgressSeed

let storedJobs: AnyJob[] = []
let queuedStoredJobs: AnyJob[] = []
let inProgressStoredJobs: AnyJob[] = []
let allStoredJobs: AnyJob[] = []
let lastFetchedStatus: string | null = null
let retryCalls: string[] = []
let discardCalls: string[] = []
let nextRetryResponse: { status: number, body?: unknown } = { status: 204 }
let nextDiscardResponse: { status: number, body?: unknown } = { status: 204 }

registerEndpoint('/api/queue/jobs', (event: unknown) => {
  const e = event as { node?: { req?: { url?: string } } }
  const url = e.node?.req?.url ?? ''
  const statusMatch = url.match(/[?&]status=([^&]+)/)
  const statusFilter = statusMatch?.[1] ?? 'failed'
  lastFetchedStatus = statusFilter

  let dataset: AnyJob[]
  switch (statusFilter) {
    case 'queued':
      dataset = queuedStoredJobs
      break
    case 'in_progress':
      dataset = inProgressStoredJobs
      break
    case 'all':
      dataset = allStoredJobs
      break
    default:
      dataset = storedJobs
  }

  return {
    data: dataset,
    meta: { page: 1, per_page: 20, total: dataset.length },
  }
})

registerEndpoint('/api/queue/jobs/1/retry', {
  method: 'POST',
  handler: (event: unknown) => {
    retryCalls.push('1')
    storedJobs = storedJobs.filter(j => j.id !== '1')
    if (nextRetryResponse.status !== 204) {
      const e = event as { node?: { res?: { statusCode: number } } }
      if (e.node?.res) {
        e.node.res.statusCode = nextRetryResponse.status
      }

      return nextRetryResponse.body ?? { errors: [{ detail: 'retry failed' }] }
    }

    return ''
  },
})

registerEndpoint('/api/queue/jobs/1/discard', {
  method: 'POST',
  handler: (event: unknown) => {
    discardCalls.push('1')
    storedJobs = storedJobs.filter(j => j.id !== '1')
    if (nextDiscardResponse.status !== 204) {
      const e = event as { node?: { res?: { statusCode: number } } }
      if (e.node?.res) {
        e.node.res.statusCode = nextDiscardResponse.status
      }

      return nextDiscardResponse.body ?? { errors: [{ detail: 'discard failed' }] }
    }

    return ''
  },
})

beforeEach(() => {
  vi.resetModules()
  storedJobs = [{ ...seed }]
  queuedStoredJobs = [{ ...queuedSeed }]
  inProgressStoredJobs = [{ ...inProgressSeed }]
  allStoredJobs = [{ ...seed }, { ...queuedSeed }, { ...inProgressSeed }]
  lastFetchedStatus = null
  retryCalls = []
  discardCalls = []
  nextRetryResponse = { status: 204 }
  nextDiscardResponse = { status: 204 }
})

describe('useQueueJobs', () => {
  it('starts empty with no error and not loading', async () => {
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { jobs, loading, error } = useQueueJobs()
    expect(jobs.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates jobs + meta from /api/queue/jobs', async () => {
    const { useQueueJobs, isFailedJob } = await import('~/composables/useQueueJobs')
    const { jobs, meta, loading, error, fetchJobs } = useQueueJobs()
    await fetchJobs()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(jobs.value).toHaveLength(1)
    expect(jobs.value[0].id).toBe('1')
    const first = jobs.value[0]
    if (isFailedJob(first)) {
      expect(first.exception_class).toBe('RuntimeException')
    } else {
      throw new Error('expected failed-row shape')
    }
    expect(meta.value).toEqual({ page: 1, per_page: 20, total: 1 })
  })

  it('retry calls POST /retry and refetches the list', async () => {
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { jobs, fetchJobs, retryJob } = useQueueJobs()
    await fetchJobs()
    expect(jobs.value).toHaveLength(1)
    await retryJob('1')
    expect(retryCalls).toEqual(['1'])
    // After retry, the mock removes the job → refetch leaves the list empty.
    expect(jobs.value).toHaveLength(0)
  })

  it('discard calls POST /discard and refetches the list', async () => {
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { jobs, fetchJobs, discardJob } = useQueueJobs()
    await fetchJobs()
    expect(jobs.value).toHaveLength(1)
    await discardJob('1')
    expect(discardCalls).toEqual(['1'])
    expect(jobs.value).toHaveLength(0)
  })

  it('surfaces the API detail on retry failure', async () => {
    nextRetryResponse = {
      status: 404,
      body: { errors: [{ detail: 'Unknown failed job id: 1' }] },
    }
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { fetchJobs, retryJob, error } = useQueueJobs()
    await fetchJobs()
    await expect(retryJob('1')).rejects.toBeDefined()
    expect(error.value).toContain('Unknown failed job id')
  })

  it('surfaces the API detail on discard failure', async () => {
    nextDiscardResponse = {
      status: 404,
      body: { errors: [{ detail: 'Unknown failed job id: 1' }] },
    }
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { fetchJobs, discardJob, error } = useQueueJobs()
    await fetchJobs()
    await expect(discardJob('1')).rejects.toBeDefined()
    expect(error.value).toContain('Unknown failed job id')
  })

  it('defaults to fetching ?status=failed when no arg passed', async () => {
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { fetchJobs } = useQueueJobs()
    await fetchJobs()
    expect(lastFetchedStatus).toBe('failed')
  })

  it('fetches queued jobs when status=queued is passed', async () => {
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { jobs, status, fetchJobs } = useQueueJobs()
    await fetchJobs(1, 20, 'queued')
    expect(lastFetchedStatus).toBe('queued')
    expect(status.value).toBe('queued')
    expect(jobs.value).toHaveLength(1)
    const row = jobs.value[0]
    if ('status' in row) {
      expect(row.status).toBe('queued')
      expect(row.reserved_at).toBeNull()
    } else {
      throw new Error('expected transport row, got failed row')
    }
  })

  it('fetches in_progress jobs when status=in_progress is passed', async () => {
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { jobs, status, fetchJobs } = useQueueJobs()
    await fetchJobs(1, 20, 'in_progress')
    expect(lastFetchedStatus).toBe('in_progress')
    expect(status.value).toBe('in_progress')
    const row = jobs.value[0]
    if ('status' in row) {
      expect(row.status).toBe('in_progress')
      expect(row.reserved_at).not.toBeNull()
    } else {
      throw new Error('expected transport row, got failed row')
    }
  })

  it('fetches mixed rows when status=all is passed', async () => {
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { jobs, status, fetchJobs } = useQueueJobs()
    await fetchJobs(1, 20, 'all')
    expect(lastFetchedStatus).toBe('all')
    expect(status.value).toBe('all')
    expect(jobs.value).toHaveLength(3)
  })
})

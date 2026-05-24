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

let storedJobs: typeof seed[] = []
let retryCalls: string[] = []
let discardCalls: string[] = []
let nextRetryResponse: { status: number, body?: unknown } = { status: 204 }
let nextDiscardResponse: { status: number, body?: unknown } = { status: 204 }

registerEndpoint('/admin/api/queue/jobs', () => ({
  data: storedJobs,
  meta: { page: 1, per_page: 20, total: storedJobs.length },
}))

registerEndpoint('/admin/api/queue/jobs/1/retry', {
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

registerEndpoint('/admin/api/queue/jobs/1/discard', {
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
    const { useQueueJobs } = await import('~/composables/useQueueJobs')
    const { jobs, meta, loading, error, fetchJobs } = useQueueJobs()
    await fetchJobs()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(jobs.value).toHaveLength(1)
    expect(jobs.value[0].id).toBe('1')
    expect(jobs.value[0].exception_class).toBe('RuntimeException')
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
})

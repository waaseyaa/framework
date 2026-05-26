// packages/admin/tests/unit/composables/useAiObservabilityRunDetail.test.ts
// useAiObservabilityRunDetail fetches GET /api/ai/observability/runs/{uuid}
// and POSTs to /replay. Mirrors useQueueJobs.test.ts patterns.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const seedDetail = {
  header: {
    traceUuid: 'trace-1',
    pipeline: 'my-pipeline',
    status: 'ok',
    startedAt: '2026-01-01T10:00:00+00:00',
    endedAt: '2026-01-01T10:00:05+00:00',
    durationMs: 5000,
    costUsd: 0.05,
    totalTokens: 300,
    spanCount: 1,
  },
  spans: [
    {
      spanUuid: 'root-span',
      parentSpanUuid: null,
      kind: 'agent',
      name: 'root-span-name',
      startedAt: '2026-01-01T10:00:00+00:00',
      endedAt: null,
      status: 'ok',
      attributes: {},
      children: [],
      truncated: false,
    },
  ],
}

let storedDetail: typeof seedDetail | null = null
let replayCalls: string[] = []
let nextReplayStatus = 201
let nextReplayNewUuid = 'new-trace-uuid'

registerEndpoint('/admin/api/ai/observability/runs/trace-1', (event: unknown) => {
  const e = event as { node?: { req?: { method?: string } } }
  if (e.node?.req?.method === 'POST') {
    // This path should not be hit (replay is a different URL)
    return { errors: [{ detail: 'wrong endpoint' }] }
  }
  if (!storedDetail) {
    const res = event as { node?: { res?: { statusCode: number } } }
    if (res.node?.res) {
      res.node.res.statusCode = 404
    }

    return { errors: [{ detail: 'Not found' }] }
  }

  return { data: storedDetail }
})

registerEndpoint('/admin/api/ai/observability/runs/trace-1/replay', {
  method: 'POST',
  handler: (event: unknown) => {
    replayCalls.push('trace-1')
    const e = event as { node?: { res?: { statusCode: number } } }
    if (e.node?.res) {
      e.node.res.statusCode = nextReplayStatus
    }

    return {
      data: {
        newRunUuid: nextReplayNewUuid,
        status: 'queued',
        startedAt: '2026-01-01T10:01:00+00:00',
      },
    }
  },
})

beforeEach(() => {
  vi.resetModules()
  storedDetail = { ...seedDetail }
  replayCalls = []
  nextReplayStatus = 201
  nextReplayNewUuid = 'new-trace-uuid'
})

describe('useAiObservabilityRunDetail', () => {
  it('starts with null run, no error, not loading', async () => {
    const { useAiObservabilityRunDetail } = await import('~/composables/useAiObservabilityRunDetail')
    const { run, loading, error } = useAiObservabilityRunDetail()
    expect(run.value).toBeNull()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates run detail from /api/ai/observability/runs/{uuid}', async () => {
    const { useAiObservabilityRunDetail } = await import('~/composables/useAiObservabilityRunDetail')
    const { run, loading, error, fetchRun } = useAiObservabilityRunDetail()
    await fetchRun('trace-1')
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(run.value).not.toBeNull()
    expect(run.value!.header.traceUuid).toBe('trace-1')
    expect(run.value!.header.pipeline).toBe('my-pipeline')
    expect(run.value!.spans).toHaveLength(1)
    expect(run.value!.spans[0].spanUuid).toBe('root-span')
  })

  it('replay calls POST and returns newRunUuid', async () => {
    const { useAiObservabilityRunDetail } = await import('~/composables/useAiObservabilityRunDetail')
    const { fetchRun, replay } = useAiObservabilityRunDetail()
    await fetchRun('trace-1')
    const result = await replay('trace-1')
    expect(replayCalls).toEqual(['trace-1'])
    expect(result.newRunUuid).toBe('new-trace-uuid')
    expect(result.status).toBe('queued')
  })

  it('surfaces error on replay failure and rethrows', async () => {
    nextReplayStatus = 500
    const { useAiObservabilityRunDetail } = await import('~/composables/useAiObservabilityRunDetail')
    const { error, replay } = useAiObservabilityRunDetail()
    await expect(replay('trace-1')).rejects.toBeDefined()
    expect(error.value).not.toBeNull()
  })
})

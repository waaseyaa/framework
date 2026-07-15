// packages/admin/tests/unit/composables/useScheduledTasks.test.ts
// useScheduledTasks fetches GET /api/scheduler/tasks and exposes a manual
// triggerTask action. Mirrors useQueueJobs.test.ts (M4B WP01).
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const seed = {
  name: 'nightly-sync',
  description: 'Sync content',
  expression: '0 2 * * *',
  timezone: null,
  last_run_at: null as string | null,
  last_status: null as string | null,
  next_run_at: '2026-05-25T02:00:00+00:00',
}

let storedTasks: typeof seed[] = []
let triggerCalls: string[] = []
let nextTriggerResponse: { status: number, body?: unknown } = {
  status: 200,
  body: { status: 'success', message: 'Task "nightly-sync" completed.' },
}

registerEndpoint('/api/scheduler/tasks', () => ({
  data: storedTasks,
}))

registerEndpoint('/api/scheduler/tasks/nightly-sync/trigger', {
  method: 'POST',
  handler: (event: unknown) => {
    triggerCalls.push('nightly-sync')
    storedTasks = storedTasks.map(t => ({
      ...t,
      last_status: 'success',
      last_run_at: '2026-05-24T18:00:00+00:00',
    }))
    if (nextTriggerResponse.status !== 200) {
      const e = event as { node?: { res?: { statusCode: number } } }
      if (e.node?.res) {
        e.node.res.statusCode = nextTriggerResponse.status
      }
    }

    return nextTriggerResponse.body ?? { status: 'success', message: 'ok' }
  },
})

beforeEach(() => {
  vi.resetModules()
  storedTasks = [{ ...seed }]
  triggerCalls = []
  nextTriggerResponse = {
    status: 200,
    body: { status: 'success', message: 'Task "nightly-sync" completed.' },
  }
})

describe('useScheduledTasks', () => {
  it('starts empty with no error and not loading', async () => {
    const { useScheduledTasks } = await import('~/composables/useScheduledTasks')
    const { tasks, loading, error } = useScheduledTasks()
    expect(tasks.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates tasks from /api/scheduler/tasks', async () => {
    const { useScheduledTasks } = await import('~/composables/useScheduledTasks')
    const { tasks, loading, error, fetchTasks } = useScheduledTasks()
    await fetchTasks()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(tasks.value).toHaveLength(1)
    expect(tasks.value[0].name).toBe('nightly-sync')
    expect(tasks.value[0].last_status).toBeNull()
  })

  it('triggerTask posts to /trigger and refetches the list', async () => {
    const { useScheduledTasks } = await import('~/composables/useScheduledTasks')
    const { tasks, fetchTasks, triggerTask } = useScheduledTasks()
    await fetchTasks()
    expect(tasks.value[0].last_status).toBeNull()

    const result = await triggerTask('nightly-sync')

    expect(triggerCalls).toEqual(['nightly-sync'])
    expect(result.status).toBe('success')
    // After trigger, the mock now reports a last_status — composable re-fetched.
    expect(tasks.value[0].last_status).toBe('success')
  })

  it('surfaces a failure message when trigger reports status=failed', async () => {
    nextTriggerResponse = {
      status: 200,
      body: {
        status: 'failed',
        message: 'kaboom',
        exception_class: 'DomainException',
      },
    }
    const { useScheduledTasks } = await import('~/composables/useScheduledTasks')
    const { fetchTasks, triggerTask } = useScheduledTasks()
    await fetchTasks()
    const result = await triggerTask('nightly-sync')
    expect(result.status).toBe('failed')
    expect(result.exception_class).toBe('DomainException')
    expect(result.message).toBe('kaboom')
  })

  it('surfaces the API detail when the trigger call fails outright (e.g. 404)', async () => {
    nextTriggerResponse = {
      status: 404,
      body: { errors: [{ detail: 'No scheduled task is registered with name "nightly-sync".' }] },
    }
    const { useScheduledTasks } = await import('~/composables/useScheduledTasks')
    const { fetchTasks, triggerTask, error } = useScheduledTasks()
    await fetchTasks()
    await expect(triggerTask('nightly-sync')).rejects.toBeDefined()
    expect(error.value).toContain('No scheduled task is registered')
  })
})

// packages/admin/tests/unit/composables/useNotificationChannels.test.ts
// useNotificationChannels fetches GET /api/notification/channels and POSTs a
// synthetic test send via /api/notification/channels/{type}/test.
// Mirrors useQueueJobs.test.ts (M4B WP01).
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

let storedChannels: Array<{ type: string, class: string }> = []
let testCalls: string[] = []
let nextTestResponse: {
  status: number
  body?: unknown
} = {
  status: 200,
  body: { type: 'mail', status: 'success', message: 'Test sent.' },
}

registerEndpoint('/api/notification/channels', () => ({
  data: storedChannels,
}))

registerEndpoint('/api/notification/channels/mail/test', {
  method: 'POST',
  handler: (event: unknown) => {
    testCalls.push('mail')
    const e = event as { node?: { res?: { statusCode: number } } }
    if (e.node?.res) {
      e.node.res.statusCode = nextTestResponse.status
    }

    return nextTestResponse.body
  },
})

registerEndpoint('/api/notification/channels/database/test', {
  method: 'POST',
  handler: (event: unknown) => {
    testCalls.push('database')
    const e = event as { node?: { res?: { statusCode: number } } }
    if (e.node?.res) {
      e.node.res.statusCode = nextTestResponse.status
    }

    return nextTestResponse.body
  },
})

beforeEach(() => {
  vi.resetModules()
  storedChannels = [
    { type: 'mail', class: 'Waaseyaa\\Notification\\Channel\\MailChannel' },
    { type: 'database', class: 'Waaseyaa\\Notification\\Channel\\DatabaseChannel' },
  ]
  testCalls = []
  nextTestResponse = {
    status: 200,
    body: { type: 'mail', status: 'success', message: 'Test sent.' },
  }
})

describe('useNotificationChannels', () => {
  it('starts with empty channels, no error, not loading', async () => {
    const { useNotificationChannels } = await import('~/composables/useNotificationChannels')
    const { channels, loading, error, lastTestResult } = useNotificationChannels()
    expect(channels.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(lastTestResult.value).toBeNull()
  })

  it('fetches and populates channels from /api/notification/channels', async () => {
    const { useNotificationChannels } = await import('~/composables/useNotificationChannels')
    const { channels, loading, error, fetchChannels } = useNotificationChannels()
    await fetchChannels()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(channels.value).toHaveLength(2)
    expect(channels.value[0].type).toBe('mail')
    expect(channels.value[0].class).toContain('MailChannel')
    expect(channels.value[1].type).toBe('database')
  })

  it('testChannel posts to /channels/{type}/test and records lastTestResult on success', async () => {
    const { useNotificationChannels } = await import('~/composables/useNotificationChannels')
    const { testChannel, lastTestResult } = useNotificationChannels()
    const result = await testChannel('mail')
    expect(testCalls).toEqual(['mail'])
    expect(result.status).toBe('success')
    expect(result.message).toBe('Test sent.')
    expect(lastTestResult.value).toEqual(result)
  })

  it('testChannel surfaces the structured failure envelope on 500', async () => {
    nextTestResponse = {
      status: 500,
      body: {
        type: 'mail',
        status: 'failed',
        message: 'SMTP unreachable',
        exception_class: 'RuntimeException',
      },
    }

    const { useNotificationChannels } = await import('~/composables/useNotificationChannels')
    const { testChannel, lastTestResult, error } = useNotificationChannels()
    const result = await testChannel('mail')

    expect(result.status).toBe('failed')
    expect(result.message).toBe('SMTP unreachable')
    expect(result.exception_class).toBe('RuntimeException')
    expect(lastTestResult.value?.status).toBe('failed')
    // 500 with the typed envelope is a "known failure" — surface it in
    // lastTestResult but leave the top-level error free so the page can
    // render the failure card without also lighting up the error banner.
    expect(error.value).toBeNull()
  })

  it('testChannel falls back to a synthetic failure when the API returns no typed body', async () => {
    nextTestResponse = {
      status: 502,
      body: { errors: [{ detail: 'Gateway down' }] },
    }

    const { useNotificationChannels } = await import('~/composables/useNotificationChannels')
    const { testChannel, error } = useNotificationChannels()
    const result = await testChannel('database')

    expect(result.status).toBe('failed')
    expect(result.message).toContain('Gateway down')
    expect(error.value).toContain('Gateway down')
  })
})

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mockNuxtImport } from '@nuxt/test-utils/runtime'

// Mock useApi so we can inject a controlled apiFetch
const { apiFetchMock } = vi.hoisted(() => ({
  apiFetchMock: vi.fn(),
}))

mockNuxtImport('useApi', () => {
  return () => ({ apiFetch: apiFetchMock })
})

mockNuxtImport('useRuntimeConfig', () => {
  return () => ({ app: { baseURL: '/' } })
})

const sampleChannels = [
  { channel: 'admin', eventCount24h: 5, lastEventAt: '2026-05-25T00:00:00Z', lastEventName: 'entity.saved' },
  { channel: 'realtime', eventCount24h: 2, lastEventAt: null, lastEventName: null },
]

const sampleEvents = [
  { id: 1, channel: 'admin', event: 'entity.saved', data: { type: 'node' }, createdAt: '2026-05-25T00:00:00Z' },
]

const sampleSubscribers = [
  { accountId: 1, accountLabel: 'Admin', channels: ['admin'], connectedSince: '2026-05-25T00:00:00Z' },
  { accountId: 0, accountLabel: null, channels: ['realtime'], connectedSince: '2026-05-25T00:01:00Z' },
]

describe('useMercureMonitor', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  it('fetchChannels populates channels ref from API response', async () => {
    apiFetchMock.mockResolvedValueOnce({ data: { rows: sampleChannels } })

    const { useMercureMonitor } = await import('~/composables/useMercureMonitor')
    const { channels, fetchChannels } = useMercureMonitor()

    await fetchChannels()

    expect(channels.value).toHaveLength(2)
    expect(channels.value[0].channel).toBe('admin')
    expect(channels.value[0].eventCount24h).toBe(5)
    expect(channels.value[1].channel).toBe('realtime')
  })

  it('fetchChannels sets error on API failure', async () => {
    apiFetchMock.mockRejectedValueOnce({ message: 'Network error' })

    const { useMercureMonitor } = await import('~/composables/useMercureMonitor')
    const { error, fetchChannels } = useMercureMonitor()

    await fetchChannels()

    expect(error.value).toBe('Network error')
  })

  it('fetchEvents populates events ref and passes channel filter as query param', async () => {
    apiFetchMock.mockResolvedValueOnce({ data: { rows: sampleEvents } })

    const { useMercureMonitor } = await import('~/composables/useMercureMonitor')
    const { events, fetchEvents } = useMercureMonitor()

    await fetchEvents(['admin'])

    expect(events.value).toHaveLength(1)
    expect(events.value[0].event).toBe('entity.saved')
    expect(apiFetchMock).toHaveBeenCalledWith('/api/mercure/events?channels=admin')
  })

  it('fetchEvents uses no query param when channelFilter is empty', async () => {
    apiFetchMock.mockResolvedValueOnce({ data: { rows: [] } })

    const { useMercureMonitor } = await import('~/composables/useMercureMonitor')
    const { fetchEvents } = useMercureMonitor()

    await fetchEvents([])

    expect(apiFetchMock).toHaveBeenCalledWith('/api/mercure/events')
  })

  it('fetchSubscribers populates subscribers ref including anonymous row', async () => {
    apiFetchMock.mockResolvedValueOnce({ data: { rows: sampleSubscribers } })

    const { useMercureMonitor } = await import('~/composables/useMercureMonitor')
    const { subscribers, fetchSubscribers } = useMercureMonitor()

    await fetchSubscribers()

    expect(subscribers.value).toHaveLength(2)
    expect(subscribers.value[0].accountLabel).toBe('Admin')
    expect(subscribers.value[1].accountId).toBe(0)
    expect(subscribers.value[1].accountLabel).toBeNull()
  })

  it('refresh calls all three fetch functions', async () => {
    apiFetchMock
      .mockResolvedValueOnce({ data: { rows: sampleChannels } })
      .mockResolvedValueOnce({ data: { rows: sampleEvents } })
      .mockResolvedValueOnce({ data: { rows: sampleSubscribers } })

    const { useMercureMonitor } = await import('~/composables/useMercureMonitor')
    const { channels, events, subscribers, refresh } = useMercureMonitor()

    await refresh()

    expect(channels.value).toHaveLength(2)
    expect(events.value).toHaveLength(1)
    expect(subscribers.value).toHaveLength(2)
    expect(apiFetchMock).toHaveBeenCalledTimes(3)
  })

  it('returns empty arrays when API returns empty rows', async () => {
    apiFetchMock
      .mockResolvedValueOnce({ data: { rows: [] } })
      .mockResolvedValueOnce({ data: { rows: [] } })
      .mockResolvedValueOnce({ data: { rows: [] } })

    const { useMercureMonitor } = await import('~/composables/useMercureMonitor')
    const { channels, events, subscribers, refresh } = useMercureMonitor()

    await refresh()

    expect(channels.value).toHaveLength(0)
    expect(events.value).toHaveLength(0)
    expect(subscribers.value).toHaveLength(0)
  })
})

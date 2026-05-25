// useMercureMonitor — admin SPA composable for the M5D broadcast monitor dashboard.
//
// Fetches channels (GET /api/mercure/channels), events (GET /api/mercure/events),
// and subscribers (GET /api/mercure/subscribers) from the backend read-model
// endpoints introduced in M5D WP01.
//
// The `channels` filter in `fetchEvents` drives a `?channels=ch1,ch2` query
// param matching the EventStreamFilter::fromQuery() contract on the PHP side.

export interface ChannelRow {
  channel: string
  eventCount24h: number
  lastEventAt: string | null
  lastEventName: string | null
}

export interface BroadcastEventRow {
  id: number
  channel: string
  event: string
  data: Record<string, unknown>
  createdAt: string
}

export interface SubscriberRow {
  accountId: number
  accountLabel: string | null
  channels: string[]
  connectedSince: string
}

export interface ChannelsResponse {
  data: { rows: ChannelRow[] }
}

export interface EventsResponse {
  data: { rows: BroadcastEventRow[] }
}

export interface SubscribersResponse {
  data: { rows: SubscriberRow[] }
}

export function useMercureMonitor() {
  const { apiFetch } = useApi()

  const channels = ref<ChannelRow[]>([])
  const events = ref<BroadcastEventRow[]>([])
  const subscribers = ref<SubscriberRow[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  /** Comma-separated channel names used to filter the events list. Empty = all. */
  const selectedChannels = ref<string[]>([])

  async function fetchChannels(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<ChannelsResponse>('/api/mercure/channels')
      channels.value = response.data?.rows ?? []
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load channels.'
    } finally {
      loading.value = false
    }
  }

  async function fetchEvents(channelFilter: string[] = []): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const params = channelFilter.length > 0
        ? `?channels=${encodeURIComponent(channelFilter.join(','))}`
        : ''
      const response = await apiFetch<EventsResponse>(`/api/mercure/events${params}`)
      events.value = response.data?.rows ?? []
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load events.'
    } finally {
      loading.value = false
    }
  }

  async function fetchSubscribers(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const response = await apiFetch<SubscribersResponse>('/api/mercure/subscribers')
      subscribers.value = response.data?.rows ?? []
    } catch (e: unknown) {
      const err = e as { data?: { errors?: Array<{ detail?: string }> }, message?: string }
      error.value = err?.data?.errors?.[0]?.detail ?? err?.message ?? 'Failed to load subscribers.'
    } finally {
      loading.value = false
    }
  }

  async function refresh(): Promise<void> {
    await Promise.all([
      fetchChannels(),
      fetchEvents(selectedChannels.value),
      fetchSubscribers(),
    ])
  }

  return {
    channels,
    events,
    subscribers,
    loading,
    error,
    selectedChannels,
    fetchChannels,
    fetchEvents,
    fetchSubscribers,
    refresh,
  }
}

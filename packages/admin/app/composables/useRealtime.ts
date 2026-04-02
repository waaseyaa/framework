import { ref, onUnmounted, type Ref } from 'vue'

export interface BroadcastMessage {
  channel: string
  event: string
  data: Record<string, unknown>
  timestamp: number
}

const MAX_RETRIES = 10
export const REALTIME_ENDPOINT_PATH = '/api/broadcast'
export const DEFAULT_REALTIME_CHANNELS = ['admin'] as const

interface UseRealtimeOptions {
  autoConnect?: boolean
}

// Runtime contract: the admin SPA consumes the backend broadcast SSE endpoint.
export function useRealtime(channels: string[] = [...DEFAULT_REALTIME_CHANNELS], options: UseRealtimeOptions = {}) {
  const messages: Ref<BroadcastMessage[]> = ref([])
  const connected = ref(false)
  const error = ref<string | null>(null)

  let eventSource: EventSource | null = null
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null
  let retryCount = 0
  let disconnectRequested = false

  function appendMessage(raw: string) {
    if (!raw || raw.trim() === '') return

    try {
      const msg: BroadcastMessage = JSON.parse(raw)
      messages.value = [...messages.value.slice(-99), msg]
    } catch (e) {
      console.warn('[Waaseyaa] Failed to parse SSE message:', raw)
    }
  }

  function connect() {
    if (typeof window === 'undefined') return
    disconnectRequested = false

    const channelParam = channels.join(',')
    eventSource = new EventSource(`${REALTIME_ENDPOINT_PATH}?channels=${channelParam}`)

    eventSource.onopen = () => {
      connected.value = true
      retryCount = 0
      error.value = null
    }

    eventSource.onmessage = (event) => {
      appendMessage(event.data)
    }

    // Server uses named SSE events.
    eventSource.addEventListener('connected', (event: MessageEvent) => appendMessage(event.data))
    eventSource.addEventListener('entity.saved', (event: MessageEvent) => appendMessage(event.data))
    eventSource.addEventListener('entity.deleted', (event: MessageEvent) => appendMessage(event.data))

    eventSource.onerror = () => {
      if (disconnectRequested) return

      connected.value = false
      if (!eventSource) return

      // Let native EventSource retry while CONNECTING; forcing close/recreate
      // here causes noisy disconnect loops on unstable dev servers.
      if (eventSource.readyState === EventSource.CONNECTING) {
        return
      }

      eventSource.close()
      eventSource = null

      retryCount++
      if (retryCount > MAX_RETRIES) {
        console.error(`[Waaseyaa] SSE connection failed after ${MAX_RETRIES} retries. Giving up.`)
        error.value = 'Real-time connection lost.'
        return
      }

      const delay = Math.min(3000 * Math.pow(2, retryCount - 1), 30000)
      console.warn(`[Waaseyaa] SSE disconnected. Reconnecting in ${delay}ms (attempt ${retryCount}/${MAX_RETRIES})`)
      reconnectTimer = setTimeout(connect, delay)
    }
  }

  function disconnect() {
    disconnectRequested = true
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
    eventSource?.close()
    eventSource = null
    connected.value = false
  }

  function reconnect() {
    retryCount = 0
    error.value = null
    connect()
  }

  if (options.autoConnect !== false) {
    connect()
  }
  onUnmounted(disconnect)

  return { messages, connected, error, connect, disconnect, reconnect }
}

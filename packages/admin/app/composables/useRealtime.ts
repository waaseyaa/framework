import { ref, onUnmounted, type Ref } from 'vue'

export interface BroadcastMessage {
  channel: string
  event: string
  data: Record<string, unknown>
  timestamp: number
}

const MAX_RETRIES = 10

// Requires a server-side SSE endpoint at /api/broadcast (not yet implemented in public/index.php).
export function useRealtime(channels: string[] = ['admin']) {
  const messages: Ref<BroadcastMessage[]> = ref([])
  const connected = ref(false)
  const error = ref<string | null>(null)

  let eventSource: EventSource | null = null
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null
  let retryCount = 0

  function connect() {
    if (typeof window === 'undefined') return

    const channelParam = channels.join(',')
    eventSource = new EventSource(`/api/broadcast?channels=${channelParam}`)

    eventSource.onopen = () => {
      connected.value = true
      retryCount = 0
    }

    eventSource.onmessage = (event) => {
      if (!event.data || event.data.trim() === '') return

      try {
        const msg: BroadcastMessage = JSON.parse(event.data)
        messages.value = [...messages.value.slice(-99), msg]
      } catch (e) {
        console.warn('[Waaseyaa] Failed to parse SSE message:', event.data)
      }
    }

    eventSource.onerror = () => {
      connected.value = false
      eventSource?.close()
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

  connect()
  onUnmounted(disconnect)

  return { messages, connected, error, disconnect, reconnect }
}

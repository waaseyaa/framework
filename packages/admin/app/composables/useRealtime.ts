import { ref, onUnmounted, type Ref } from 'vue'

export interface BroadcastMessage {
  id: number
  channel: string
  event: string
  data: Record<string, unknown>
  created_at: number
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
  // The non-secret per-session pairing token from the server's `connected` SSE
  // frame (server derives it as substr(sha256(session_id), 0, 32)). Surfacing it
  // is what lets a presenter target THIS viewer's session for a Wayfinding live
  // trail (Phase 2 / FR-004) — the server already isolates delivery by token.
  const sessionToken = ref<string | null>(null)

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

  // Capture the per-session pairing token from the `connected` frame, whose
  // payload is `{ channels, sessionToken }` (not a BroadcastMessage envelope).
  function captureSessionToken(raw: string) {
    if (!raw || raw.trim() === '') return

    try {
      const payload = JSON.parse(raw) as { sessionToken?: unknown }
      sessionToken.value = typeof payload.sessionToken === 'string' ? payload.sessionToken : null
    } catch {
      // appendMessage already warns on malformed payloads; nothing to surface.
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

    // Server uses named SSE events. The `connected` frame additionally carries
    // this connection's own session pairing token — capture it for presenters.
    eventSource.addEventListener('connected', (event: MessageEvent) => {
      captureSessionToken(event.data)
      appendMessage(event.data)
    })
    eventSource.addEventListener('entity.saved', (event: MessageEvent) => appendMessage(event.data))
    eventSource.addEventListener('entity.deleted', (event: MessageEvent) => appendMessage(event.data))
    // Wayfinding beacons arrive on this connection's own (server-derived) session
    // channel — see useBeacons for the trail/overlay consumer.
    eventSource.addEventListener('wayfinding.beacon', (event: MessageEvent) => appendMessage(event.data))

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
    sessionToken.value = null
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

  return { messages, connected, error, sessionToken, connect, disconnect, reconnect }
}

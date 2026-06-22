import { ref, onUnmounted, getCurrentInstance, type Ref } from 'vue'

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

/**
 * One live SSE connection, shared by every consumer that asks for the same
 * channel set. The admin SPA mounts several realtime consumers at once (the
 * persistent WayfindingOverlay via useBeacons, plus each SchemaList), and an
 * EventSource pins a FrankenPHP worker for the life of the stream — so giving
 * each consumer its own connection multiplied the worker pressure and produced
 * the hydration "reconnect storm" (many short-lived `/api/broadcast` connects
 * thrashing the pool). Sharing one connection per channel set is the fix: all
 * consumers read the same `messages`/`connected`/`sessionToken` refs and filter
 * by event type themselves.
 */
interface SharedConnection {
  channelParam: string
  messages: Ref<BroadcastMessage[]>
  connected: Ref<boolean>
  error: Ref<string | null>
  sessionToken: Ref<string | null>
  refCount: number
  connect: () => void
  reconnect: () => void
  teardown: () => void
}

const sharedConnections = new Map<string, SharedConnection>()

function createSharedConnection(channels: string[]): SharedConnection {
  const channelParam = channels.join(',')
  const messages: Ref<BroadcastMessage[]> = ref([])
  const connected = ref(false)
  const error = ref<string | null>(null)
  // The non-secret per-session pairing token from the server's `connected` SSE
  // frame (server derives it as substr(sha256(session_id), 0, 32)). Surfacing it
  // lets a presenter target THIS viewer's session for a Wayfinding live trail;
  // the supported, race-free read path is GET /api/wayfinding/session, but the
  // value is identical to what arrives here.
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
    } catch {
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
    // Idempotent: a live (or still-connecting) stream is reused by every
    // consumer, so a second consumer calling connect() never opens a rival
    // EventSource.
    if (eventSource && eventSource.readyState !== EventSource.CLOSED) return
    disconnectRequested = false

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
    // channel — see useBeacons for the trail/overlay consumer. The server replays
    // still-active beacons on (re)connect, so a beacon survives reconnects.
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

  function teardown() {
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
    if (eventSource) {
      eventSource.close()
      eventSource = null
    }
    connect()
  }

  return { channelParam, messages, connected, error, sessionToken, refCount: 0, connect, reconnect, teardown }
}

// Runtime contract: the admin SPA consumes the backend broadcast SSE endpoint
// through a SINGLE shared connection per channel set (see SharedConnection).
export function useRealtime(channels: string[] = [...DEFAULT_REALTIME_CHANNELS], options: UseRealtimeOptions = {}) {
  const key = channels.join(',')
  let shared = sharedConnections.get(key)
  if (!shared) {
    shared = createSharedConnection(channels)
    sharedConnections.set(key, shared)
  }
  shared.refCount++

  // Per-consumer release: the shared connection is only torn down when its LAST
  // consumer goes away. A SchemaList unmounting on navigation therefore never
  // kills the connection the persistent overlay still depends on.
  let released = false
  function release() {
    if (released) return
    released = true
    const conn = sharedConnections.get(key)
    if (!conn) return
    conn.refCount--
    if (conn.refCount <= 0) {
      conn.teardown()
      sharedConnections.delete(key)
    }
  }

  if (options.autoConnect !== false) {
    shared.connect()
  }
  if (getCurrentInstance()) {
    onUnmounted(release)
  }

  return {
    messages: shared.messages,
    connected: shared.connected,
    error: shared.error,
    sessionToken: shared.sessionToken,
    connect: shared.connect,
    disconnect: release,
    reconnect: shared.reconnect,
  }
}

/** Test-only: drop all shared connections so each test starts isolated. */
export function __resetRealtime(): void {
  for (const conn of sharedConnections.values()) {
    conn.teardown()
  }
  sharedConnections.clear()
}

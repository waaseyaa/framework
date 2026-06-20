import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_REALTIME_CHANNELS, REALTIME_ENDPOINT_PATH, useRealtime, __resetRealtime } from '~/composables/useRealtime'

vi.mock('vue', async () => {
  const actual = await vi.importActual<typeof import('vue')>('vue')
  return {
    ...actual,
    onUnmounted: () => {
      // no-op in unit tests outside component scope
    },
  }
})

class MockEventSource {
  static CONNECTING = 0
  static OPEN = 1
  static CLOSED = 2
  static instances: MockEventSource[] = []

  readonly url: string
  readyState = MockEventSource.CONNECTING
  onopen: ((event: Event) => void) | null = null
  onmessage: ((event: MessageEvent) => void) | null = null
  onerror: ((event: Event) => void) | null = null
  private listeners: Record<string, Array<(event: MessageEvent) => void>> = {}

  constructor(url: string) {
    this.url = url
    MockEventSource.instances.push(this)
  }

  addEventListener(type: string, cb: (event: MessageEvent) => void) {
    this.listeners[type] ||= []
    this.listeners[type].push(cb)
  }

  close() {
    this.readyState = MockEventSource.CLOSED
  }

  emitOpen() {
    this.readyState = MockEventSource.OPEN
    this.onopen?.(new Event('open'))
  }

  emitError() {
    this.onerror?.(new Event('error'))
  }

  emitNamed(type: string, data: unknown) {
    const event = { data: JSON.stringify(data) } as MessageEvent
    for (const cb of this.listeners[type] || []) {
      cb(event)
    }
  }
}

describe('useRealtime', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    MockEventSource.instances = []
    vi.stubGlobal('EventSource', MockEventSource as unknown as typeof EventSource)
    // Connections are shared at module scope; drop any from a prior test so
    // each test starts from a clean slate.
    __resetRealtime()
  })

  afterEach(() => {
    __resetRealtime()
    vi.useRealTimers()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('subscribes and parses named SSE events', () => {
    const { messages, connected } = useRealtime(['admin'])
    const es = MockEventSource.instances[0]

    es.emitOpen()
    expect(connected.value).toBe(true)

    es.emitNamed('entity.saved', {
      id: 1,
      channel: 'admin',
      event: 'entity.saved',
      data: { id: '1' },
      created_at: 1,
    })

    expect(messages.value).toHaveLength(1)
    expect(messages.value[0].event).toBe('entity.saved')
  })

  it('does not force reconnect while native EventSource is CONNECTING', () => {
    useRealtime(['admin'])
    const es = MockEventSource.instances[0]
    es.readyState = MockEventSource.CONNECTING

    es.emitError()
    vi.advanceTimersByTime(31_000)

    expect(MockEventSource.instances).toHaveLength(1)
  })

  it('schedules reconnect when stream is CLOSED', () => {
    useRealtime(['admin'])
    const es = MockEventSource.instances[0]
    es.readyState = MockEventSource.CLOSED

    es.emitError()
    vi.advanceTimersByTime(3_000)

    expect(MockEventSource.instances).toHaveLength(2)
  })

  it('supports manual connect when autoConnect is disabled', () => {
    const realtime = useRealtime(['admin'], { autoConnect: false })
    expect(MockEventSource.instances).toHaveLength(0)

    realtime.connect()
    expect(MockEventSource.instances).toHaveLength(1)
  })

  it('uses the canonical broadcast endpoint and default admin channel', () => {
    useRealtime()

    expect(MockEventSource.instances).toHaveLength(1)
    expect(MockEventSource.instances[0].url).toBe(`${REALTIME_ENDPOINT_PATH}?channels=${DEFAULT_REALTIME_CHANNELS[0]}`)
  })

  // P0-1 (wayfinding-showcase-hardening): multiple consumers of the same channel
  // set share ONE EventSource — the persistent overlay (useBeacons) plus each
  // SchemaList must not each pin their own FrankenPHP worker (the hydration
  // "reconnect storm" / worker-starvation fix).
  it('shares a single EventSource across consumers on the same channel set', () => {
    const a = useRealtime(['admin'])
    const b = useRealtime(['admin'])

    expect(MockEventSource.instances).toHaveLength(1)

    // Both consumers observe the same stream and pairing token.
    const es = MockEventSource.instances[0]
    es.emitNamed('connected', { channels: ['admin', 'session:tok'], sessionToken: 'tok' })
    expect(a.sessionToken.value).toBe('tok')
    expect(b.sessionToken.value).toBe('tok')

    es.emitNamed('entity.saved', { id: 1, channel: 'admin', event: 'entity.saved', data: {}, created_at: 1 })
    expect(a.messages.value).toBe(b.messages.value)
  })

  it('keeps the shared stream alive until the last consumer releases it', () => {
    const a = useRealtime(['admin'])
    const b = useRealtime(['admin'])
    expect(MockEventSource.instances).toHaveLength(1)

    // One consumer leaving does not tear down the connection the other needs.
    a.disconnect()
    expect(MockEventSource.instances[0].readyState).not.toBe(MockEventSource.CLOSED)

    // The last consumer leaving tears it down.
    b.disconnect()
    expect(MockEventSource.instances[0].readyState).toBe(MockEventSource.CLOSED)
  })

  // P0-2 (wayfinding-stress-remediation-01KVGK4Q): the admin client must expose
  // its own session pairing token so a presenter can target this exact session.
  it('exposes the session pairing token from the connected frame', () => {
    const { sessionToken } = useRealtime(['admin'])
    const es = MockEventSource.instances[0]

    es.emitOpen()
    expect(sessionToken.value).toBeNull()

    es.emitNamed('connected', { channels: ['admin', 'session:abc123'], sessionToken: 'abc123' })
    expect(sessionToken.value).toBe('abc123')
  })

  it('leaves the session token null when the connected frame omits it', () => {
    const { sessionToken } = useRealtime(['admin'])
    const es = MockEventSource.instances[0]

    es.emitNamed('connected', { channels: ['admin'] })
    expect(sessionToken.value).toBeNull()
  })

  it('clears the session token on disconnect', () => {
    const realtime = useRealtime(['admin'])
    const es = MockEventSource.instances[0]

    es.emitNamed('connected', { channels: ['admin', 'session:abc123'], sessionToken: 'abc123' })
    expect(realtime.sessionToken.value).toBe('abc123')

    realtime.disconnect()
    expect(realtime.sessionToken.value).toBeNull()
  })
})

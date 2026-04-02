import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_REALTIME_CHANNELS, REALTIME_ENDPOINT_PATH, useRealtime } from '~/composables/useRealtime'

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
  })

  afterEach(() => {
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
      channel: 'admin',
      event: 'entity.saved',
      data: { id: '1' },
      timestamp: 1,
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
})

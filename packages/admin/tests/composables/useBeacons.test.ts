// Acceptance for the Wayfinding beacon trail consumer (Phase 3): wayfinding.beacon
// SSE events build a live trail, the overlay auto-advances to the newest beacon,
// navigation moves within the trail, and dismiss hides until a new beacon arrives.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent } from 'vue'
import * as realtimeModule from '~/composables/useRealtime'
import { useBeacons } from '~/composables/useBeacons'

// vitest hoists vi.mock above all imports, so useBeacons receives the mocked
// useRealtime even though this appears after the imports in source order.
vi.mock('~/composables/useRealtime', () => {
  const { ref } = require('vue') as typeof import('vue')
  const messages = ref<unknown[]>([])
  const connected = ref(true)
  return { useRealtime: () => ({ messages, connected }), __messages: messages }
})

const messages = (realtimeModule as unknown as { __messages: { value: unknown[] } }).__messages

function beaconMsg(id: number, anchorId: string, content: string, order = 0) {
  return { id, channel: 'session:x', event: 'wayfinding.beacon', data: { anchor_id: anchorId, content, order }, created_at: id }
}

function mountBeacons() {
  let api!: ReturnType<typeof useBeacons>
  const cmp = defineComponent({
    setup() {
      api = useBeacons()
      return () => null
    },
  })
  mount(cmp)
  return api
}

beforeEach(() => {
  messages.value = []
})

describe('useBeacons', () => {
  it('builds a visible trail from beacon events and auto-advances to the newest', async () => {
    const api = mountBeacons()
    messages.value = [beaconMsg(1, 'field:node:title', 'Edit the title'), beaconMsg(2, 'action:node:submit', 'Now save')]
    await flushPromises()

    expect(api.trail.value).toHaveLength(2)
    expect(api.activeIndex.value).toBe(1)
    expect(api.active.value?.content).toBe('Now save')
    expect(api.visible.value).toBe(true)
  })

  it('ignores non-beacon events and malformed beacons', async () => {
    const api = mountBeacons()
    messages.value = [
      { id: 1, channel: 'admin', event: 'entity.saved', data: { entityType: 'node', id: '1' }, created_at: 1 },
      beaconMsg(2, '', 'no anchor'),
      { id: 3, channel: 'session:x', event: 'wayfinding.beacon', data: { anchor_id: 'a', content: 123 }, created_at: 3 },
      beaconMsg(4, 'field:node:title', 'valid'),
    ]
    await flushPromises()

    expect(api.trail.value).toHaveLength(1)
    expect(api.active.value?.anchorId).toBe('field:node:title')
  })

  it('navigates within the trail with next/prev (bounded)', async () => {
    const api = mountBeacons()
    messages.value = [beaconMsg(1, 'a', 'one'), beaconMsg(2, 'b', 'two'), beaconMsg(3, 'c', 'three')]
    await flushPromises()

    expect(api.activeIndex.value).toBe(2)
    api.next() // already at end — no-op
    expect(api.activeIndex.value).toBe(2)
    api.prev()
    api.prev()
    expect(api.active.value?.content).toBe('one')
    api.prev() // at start — no-op
    expect(api.activeIndex.value).toBe(0)
  })

  it('dismiss hides the overlay; a new beacon re-shows it', async () => {
    const api = mountBeacons()
    messages.value = [beaconMsg(1, 'a', 'one')]
    await flushPromises()
    expect(api.visible.value).toBe(true)

    api.dismiss()
    expect(api.visible.value).toBe(false)

    messages.value = [...messages.value, beaconMsg(2, 'b', 'two')]
    await flushPromises()
    expect(api.visible.value).toBe(true)
    expect(api.active.value?.content).toBe('two')
  })
})

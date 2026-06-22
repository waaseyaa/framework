import { ref, computed, watch, type Ref } from 'vue'
import { useRealtime, type BroadcastMessage } from '~/composables/useRealtime'
import { useApi } from '~/composables/useApi'

/**
 * A single Wayfinding beacon: one element-anchored tip in a live trail.
 */
export interface Beacon {
  anchorId: string
  content: string
  order: number
}

/**
 * Consumes the per-session Wayfinding beacon stream (delivered on this
 * connection's own server-derived SSE channel) and exposes the live trail plus
 * keyboard-friendly navigation for the overlay.
 *
 * Each incoming `wayfinding.beacon` is appended to the trail in arrival order;
 * the overlay auto-advances to the newest one (a live agent "walks" the user
 * through), and a new beacon re-shows a previously dismissed overlay. Navigation
 * (next/prev) lets the user move within the trail; dismiss hides it.
 */
export function useBeacons() {
  const { messages, connected, sessionToken } = useRealtime()

  const trail: Ref<Beacon[]> = ref([])
  const activeIndex = ref(0)
  const dismissed = ref(false)

  // Broadcast row id high-water mark, so a message that lingers in the shared
  // `messages` buffer is never re-appended to the trail.
  let lastSeenId = 0

  function ingest(messageList: BroadcastMessage[]): void {
    for (const message of messageList) {
      if (message.event !== 'wayfinding.beacon' || message.id <= lastSeenId) {
        continue
      }
      lastSeenId = message.id

      const data = message.data ?? {}
      const anchorId = typeof data.anchor_id === 'string' ? data.anchor_id : ''
      const content = typeof data.content === 'string' ? data.content : ''
      if (anchorId === '' || content === '') {
        continue
      }
      const order = typeof data.order === 'number' ? data.order : trail.value.length

      trail.value = [...trail.value, { anchorId, content, order }]
      activeIndex.value = trail.value.length - 1 // auto-advance to the live beacon
      dismissed.value = false // a new beacon re-shows a dismissed overlay
    }
  }

  watch(messages, ingest, { deep: true, immediate: true })

  const active = computed<Beacon | null>(() => trail.value[activeIndex.value] ?? null)
  const visible = computed(() => !dismissed.value && active.value !== null)
  const hasPrev = computed(() => activeIndex.value > 0)
  const hasNext = computed(() => activeIndex.value < trail.value.length - 1)

  function next(): void {
    if (hasNext.value) {
      activeIndex.value += 1
    }
  }

  function prev(): void {
    if (hasPrev.value) {
      activeIndex.value -= 1
    }
  }

  function dismiss(): void {
    dismissed.value = true
    // Tell the server to drop this session's retained beacons so a dismissed
    // trail does not replay on the next reconnect/reload ("non-dismissed"
    // survives, dismissed does not). Own-session scoped and best-effort —
    // resolved lazily and fully guarded so a dismiss never throws even if the
    // API layer is unavailable; the worst case is tips reappearing on reload.
    try {
      void useApi().apiFetch('/api/wayfinding/beacons', { method: 'DELETE' }).catch(() => {})
    } catch {
      // best-effort
    }
  }

  // `sessionToken` is re-exposed so a presenter pairing UI can read THIS
  // connection's session token (the beacon overlay consumer is the natural place
  // for it to live) and hand it to a guiding agent / the emit endpoint.
  return { trail, active, activeIndex, visible, hasPrev, hasNext, connected, sessionToken, next, prev, dismiss }
}

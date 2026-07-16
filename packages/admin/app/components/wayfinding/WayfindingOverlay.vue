<script setup lang="ts">
import { watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useBeacons } from '~/composables/useBeacons'
import { useLanguage } from '~/composables/useLanguage'

// The flagship Wayfinding overlay: renders the active beacon of a live trail,
// anchored to its declared data-anchor element, with full accessibility (LD-6 /
// FR-012). Built on the alpha.226 role="status" aria-live primitive: keyboard
// navigable (←/→ or ↑/↓ move, Esc dismisses), beacon text announced via aria-live,
// focus moved to the anchored element WITHOUT trapping, dismissable at any time,
// and honours prefers-reduced-motion.

const { t } = useLanguage()
const { active, activeIndex, trail, visible, hasPrev, hasNext, next, prev, dismiss } = useBeacons()

const HIGHLIGHT_CLASS = 'wf-anchored'
const TRANSIENT_TABINDEX = 'data-wf-transient-tabindex'

function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function escapeAnchor(anchorId: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(anchorId)
  }
  return anchorId.replace(/["\\]/g, '\\$&')
}

function findAnchored(anchorId: string): HTMLElement | null {
  if (typeof document === 'undefined') return null
  return document.querySelector<HTMLElement>(`[data-anchor="${escapeAnchor(anchorId)}"]`)
}

function isNativelyFocusable(el: HTMLElement): boolean {
  return ['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName) || el.tabIndex >= 0
}

function clearHighlights(): void {
  if (typeof document === 'undefined') return
  document.querySelectorAll<HTMLElement>('.' + HIGHLIGHT_CLASS).forEach((el) => {
    el.classList.remove(HIGHLIGHT_CLASS)
    if (el.hasAttribute(TRANSIENT_TABINDEX)) {
      el.removeAttribute('tabindex')
      el.removeAttribute(TRANSIENT_TABINDEX)
    }
  })
}

function spotlight(anchorId: string): void {
  clearHighlights()
  const el = findAnchored(anchorId)
  if (!el) return // graceful: the card still shows; nothing to anchor to yet

  el.classList.add(HIGHLIGHT_CLASS)

  if (typeof el.scrollIntoView === 'function') {
    el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'center' })
  }

  // Move focus to the anchored element so keyboard users land on the thing the
  // beacon points at — but never trap focus (the page stays fully tabbable).
  if (typeof el.focus === 'function') {
    if (!isNativelyFocusable(el)) {
      el.setAttribute('tabindex', '-1')
      el.setAttribute(TRANSIENT_TABINDEX, '')
    }
    el.focus()
  }
}

watch(active, async (beacon) => {
  if (!beacon) {
    clearHighlights()
    return
  }
  await nextTick()
  spotlight(beacon.anchorId)
}, { immediate: true })

watch(visible, (isVisible) => {
  if (!isVisible) clearHighlights()
})

function onKeydown(event: KeyboardEvent): void {
  if (!visible.value) return
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
    event.preventDefault()
    next()
  } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
    event.preventDefault()
    prev()
  } else if (event.key === 'Escape') {
    event.preventDefault()
    dismiss()
  }
}

onMounted(() => {
  if (typeof window !== 'undefined') window.addEventListener('keydown', onKeydown)
})
onUnmounted(() => {
  if (typeof window !== 'undefined') window.removeEventListener('keydown', onKeydown)
  clearHighlights()
})
</script>

<template>
  <div
    v-if="visible && active"
    class="wf-beacon"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    :data-anchor-target="active.anchorId"
  >
    <p class="wf-beacon__content">{{ active.content }}</p>
    <div class="wf-beacon__nav">
      <span class="wf-beacon__step" aria-hidden="true">{{ activeIndex + 1 }} / {{ trail.length }}</span>
      <button
        type="button"
        class="wf-beacon__btn touch-target"
        :disabled="!hasPrev"
        :aria-label="t('previous')"
        @click="prev"
      >‹</button>
      <button
        type="button"
        class="wf-beacon__btn touch-target"
        :disabled="!hasNext"
        :aria-label="t('next')"
        @click="next"
      >›</button>
      <button
        type="button"
        class="wf-beacon__btn wf-beacon__dismiss touch-target"
        :aria-label="t('dismiss')"
        @click="dismiss"
      >×</button>
    </div>
  </div>
</template>

<style scoped>
.wf-beacon {
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 1000;
  max-width: min(420px, calc(100vw - 32px));
  padding: 14px 16px;
  border-radius: 10px;
  background: var(--color-primary, #0f766e);
  color: #fff;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
  display: flex;
  flex-direction: column;
  gap: 10px;
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.wf-beacon__content {
  margin: 0;
  font-size: 14px;
  line-height: 1.45;
}

.wf-beacon__nav {
  display: flex;
  align-items: center;
  gap: 6px;
}

.wf-beacon__step {
  font-size: 12px;
  opacity: 0.85;
  margin-right: auto;
}

.wf-beacon__btn {
  min-width: 28px;
  height: 28px;
  border: 1px solid rgba(255, 255, 255, 0.4);
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  font-size: 16px;
  line-height: 1;
  cursor: pointer;
}

.wf-beacon__btn:disabled {
  opacity: 0.4;
  cursor: default;
}

.wf-beacon__btn:focus-visible {
  outline: 2px solid #fff;
  outline-offset: 2px;
}
</style>

<style>
/* Global (unscoped): the highlight ring is applied to arbitrary anchored
   elements outside this component's scope. */
.wf-anchored {
  outline: 3px solid var(--color-primary, #0f766e) !important;
  outline-offset: 2px;
  border-radius: 3px;
  scroll-margin: 80px;
}

@media (prefers-reduced-motion: reduce) {
  .wf-beacon {
    transition: none;
  }
}
</style>

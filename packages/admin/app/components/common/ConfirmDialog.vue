<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useLanguage } from '~/composables/useLanguage'

const props = withDefaults(defineProps<{
  open: boolean
  message: string
  title?: string
  confirmLabel?: string
  dangerous?: boolean
}>(), {
  title: '',
  confirmLabel: '',
  dangerous: false,
})

const emit = defineEmits<{ confirm: [], cancel: [] }>()
const { t } = useLanguage()
const cancelButton = ref<HTMLButtonElement | null>(null)
const dialog = ref<HTMLElement | null>(null)
const focusableSelector = 'button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

watch(() => props.open, async (open) => {
  if (!open) return
  await nextTick()
  cancelButton.value?.focus()
})

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    emit('cancel')
    return
  }
  if (event.key !== 'Tab' || !dialog.value) return
  const focusable = [...dialog.value.querySelectorAll<HTMLElement>(focusableSelector)]
  const first = focusable[0]
  const last = focusable.at(-1)
  if (!first || !last) {
    event.preventDefault()
    return
  }
  const active = document.activeElement
  if (event.shiftKey && (active === first || !dialog.value.contains(active))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (active === last || !dialog.value.contains(active))) {
    event.preventDefault()
    first.focus()
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="confirm-overlay" @click.self="emit('cancel')" @keydown="onKeydown">
      <section
        ref="dialog"
        class="confirm-dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-dialog-title"
        aria-describedby="confirm-dialog-message"
      >
        <h2 id="confirm-dialog-title">{{ title || t('confirm_action') }}</h2>
        <p id="confirm-dialog-message">{{ message }}</p>
        <div class="confirm-actions">
          <button ref="cancelButton" type="button" class="btn" data-testid="confirm-dialog-cancel" @click="emit('cancel')">
            {{ t('cancel') }}
          </button>
          <button
            type="button"
            :class="dangerous ? 'btn btn--danger' : 'btn btn--primary'"
            data-testid="confirm-dialog-confirm"
            @click="emit('confirm')"
          >
            {{ confirmLabel || t('confirm') }}
          </button>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.confirm-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: grid;
  place-items: center;
  padding: 1.5rem;
  background: rgba(0, 0, 0, 0.55);
}
.confirm-dialog {
  width: min(100%, 30rem);
  padding: 1.5rem;
  border-radius: 0.5rem;
  background: var(--color-surface, #fff);
  box-shadow: 0 1rem 2.5rem rgba(0, 0, 0, 0.3);
}
.confirm-dialog h2 { margin: 0 0 0.75rem; font-size: 1.25rem; }
.confirm-dialog p { margin: 0; }
.confirm-actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; }
.btn--danger { background: #b42318; color: #fff; }
</style>

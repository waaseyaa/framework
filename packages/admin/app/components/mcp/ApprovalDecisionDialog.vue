<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import type { McpApprovalRow } from '~/composables/useMcpApprovals'
import { useLanguage } from '~/composables/useLanguage'

// Mirrors packages/foundation ApprovalRequest::MAX_DECISION_REASON_LENGTH:
// at most 500 Unicode characters, single line, no control characters.
const MAX_REASON_LENGTH = 500

const props = defineProps<{
  open: boolean
  decision: 'approve' | 'deny'
  request: McpApprovalRow | null
  submitting: boolean
}>()

const emit = defineEmits<{ confirm: [reason: string], cancel: [] }>()
const { t } = useLanguage()
const cancelButton = ref<HTMLButtonElement | null>(null)
const dialogEl = ref<HTMLElement | null>(null)
const reason = ref('')

watch(() => props.open, async (open) => {
  if (!open) return
  reason.value = ''
  await nextTick()
  cancelButton.value?.focus()
})

/**
 * Dismissal is refused while a decision is in flight so Escape, an overlay
 * click, or the cancel button can never strand an in-flight action.
 */
function requestCancel(): void {
  if (props.submitting) return
  emit('cancel')
}

const FOCUSABLE_SELECTOR
  = 'button:not([disabled]), [href], input:not([disabled]), textarea:not([disabled]), '
    + 'select:not([disabled]), [tabindex]:not([tabindex="-1"])'

/** Minimal focus trap: Tab and Shift+Tab cycle within the open dialog. */
function onOverlayKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    requestCancel()
    return
  }
  if (event.key !== 'Tab' || dialogEl.value === null) return

  const focusables = Array.from(dialogEl.value.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
  const first = focusables[0]
  const last = focusables[focusables.length - 1]
  if (first === undefined || last === undefined) {
    event.preventDefault()
    return
  }
  const active = document.activeElement
  const insideDialog = active instanceof HTMLElement && dialogEl.value.contains(active)

  if (event.shiftKey) {
    if (!insideDialog || active === first) {
      event.preventDefault()
      last.focus()
    }
  } else if (!insideDialog || active === last) {
    event.preventDefault()
    first.focus()
  }
}

// Unicode characters = code points, matching the server's mb-aware bound.
const reasonLength = computed(() => [...reason.value.trim()].length)
const remaining = computed(() => Math.max(0, MAX_REASON_LENGTH - reasonLength.value))
const reasonInvalid = computed(() =>
  reasonLength.value > MAX_REASON_LENGTH
  // eslint-disable-next-line no-control-regex
  || /[\u0000-\u001F\u007F]/u.test(reason.value.trim()),
)
const confirmDisabled = computed(() => props.submitting || reasonInvalid.value)

function confirm(): void {
  if (confirmDisabled.value) return
  emit('confirm', reason.value.trim())
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleString()
  } catch {
    return iso
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open && request" class="confirm-overlay" @click.self="requestCancel" @keydown="onOverlayKeydown">
      <section
        ref="dialogEl"
        class="confirm-dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="approval-decision-title"
        aria-describedby="approval-decision-summary"
      >
        <h2 id="approval-decision-title">
          {{ decision === 'approve' ? t('mcp_approvals_decide_approve_title') : t('mcp_approvals_decide_deny_title') }}
        </h2>
        <dl id="approval-decision-summary" class="decision-summary">
          <div class="decision-summary__row">
            <dt>{{ t('mcp_approvals_col_operation') }}</dt>
            <dd data-testid="approval-decision-operation">{{ request.operation }}</dd>
          </div>
          <div class="decision-summary__row">
            <dt>{{ t('mcp_approvals_col_surface') }}</dt>
            <dd>{{ request.surface }}</dd>
          </div>
          <div class="decision-summary__row">
            <dt>{{ t('mcp_approvals_col_safe_arguments') }}</dt>
            <dd><code data-testid="approval-decision-safe-arguments">{{ JSON.stringify(request.safeArguments) }}</code></dd>
          </div>
          <div class="decision-summary__row">
            <dt>{{ t('mcp_approvals_col_requested') }}</dt>
            <dd>{{ formatDate(request.requestedAt) }}</dd>
          </div>
          <div class="decision-summary__row">
            <dt>{{ t('mcp_approvals_col_expires') }}</dt>
            <dd>{{ formatDate(request.expiresAt) }}</dd>
          </div>
          <div class="decision-summary__row">
            <dt>{{ t('mcp_approvals_col_principal') }}</dt>
            <dd>{{ request.principal }}</dd>
          </div>
          <div class="decision-summary__row">
            <dt>{{ t('mcp_approvals_col_correlation') }}</dt>
            <dd><code>{{ request.correlationId }}</code></dd>
          </div>
          <div class="decision-summary__row">
            <dt>{{ t('mcp_approvals_col_fingerprint') }}</dt>
            <dd><code>{{ request.argumentsFingerprint }}</code></dd>
          </div>
        </dl>

        <label class="reason-label" for="approval-decision-reason">{{ t('mcp_approvals_reason_label') }}</label>
        <textarea
          id="approval-decision-reason"
          v-model="reason"
          data-testid="approval-decision-reason"
          class="reason-input"
          rows="3"
          :aria-invalid="reasonInvalid ? 'true' : undefined"
          :aria-describedby="reasonInvalid ? 'approval-decision-reason-error' : 'approval-decision-reason-remaining'"
        />
        <p
          id="approval-decision-reason-remaining"
          class="reason-remaining"
          aria-live="polite"
          data-testid="approval-decision-reason-remaining"
        >
          {{ t('mcp_approvals_reason_remaining', { count: String(remaining) }) }}
        </p>
        <p
          v-if="reasonInvalid"
          id="approval-decision-reason-error"
          class="reason-error"
          role="alert"
          data-testid="approval-decision-reason-error"
        >
          {{ t('mcp_approvals_reason_error') }}
        </p>

        <div class="confirm-actions">
          <button
            ref="cancelButton"
            type="button"
            class="btn"
            data-testid="approval-decision-cancel"
            :aria-disabled="submitting ? 'true' : undefined"
            @click="requestCancel"
          >
            {{ t('cancel') }}
          </button>
          <button
            type="button"
            :class="decision === 'deny' ? 'btn btn--danger' : 'btn btn--primary'"
            data-testid="approval-decision-confirm"
            :disabled="confirmDisabled"
            @click="confirm"
          >
            {{ decision === 'approve' ? t('mcp_approvals_approve') : t('mcp_approvals_deny') }}
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
  width: min(100%, 34rem);
  max-height: min(100%, 90vh);
  overflow-y: auto;
  padding: 1.5rem;
  border-radius: 0.5rem;
  background: var(--color-surface, #fff);
  box-shadow: 0 1rem 2.5rem rgba(0, 0, 0, 0.3);
}
.confirm-dialog h2 { margin: 0 0 0.75rem; font-size: 1.25rem; }
.decision-summary { margin: 0 0 1rem; }
.decision-summary__row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.75rem;
  padding: 0.25rem 0;
  border-bottom: 1px solid var(--color-border, #e5e7eb);
}
.decision-summary__row dt {
  flex: 0 0 10rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-muted, #6b7280);
}
.decision-summary__row dd {
  flex: 1 1 12rem;
  margin: 0;
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}
.reason-label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.25rem; }
.reason-input {
  width: 100%;
  box-sizing: border-box;
  padding: 0.5rem;
  border: 1px solid var(--color-border, #d1d5db);
  border-radius: 0.375rem;
  font: inherit;
  resize: vertical;
}
.reason-remaining { margin: 0.25rem 0 0; font-size: 0.75rem; color: var(--color-muted, #6b7280); }
.reason-error { margin: 0.25rem 0 0; font-size: 0.8125rem; color: #b42318; }
.confirm-actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; }
.btn--danger { background: #b42318; color: #fff; }
</style>

<script setup lang="ts">
import type { McpApprovalRow } from '~/composables/useMcpApprovals'
import { useMcpApprovals } from '~/composables/useMcpApprovals'

// Operator page for the MCP write-tier human-approval gate (#2177 F1 C1c).
// Capability-aware via the server-authoritative session projection: the
// server routes stay the enforcement boundary — can() only shapes the UI.
const { can } = useAdmin()
const { t } = useLanguage()

const canView = computed(() => can('mcp.approval.view'))
const canDecide = computed(() => can('mcp.approval.decide'))

const {
  requests,
  loading,
  error,
  forbidden,
  hasNext,
  hasPrevious,
  fetchFirstPage,
  nextPage,
  previousPage,
  refresh,
  decide,
} = useMcpApprovals()

const dialogOpen = ref(false)
const dialogDecision = ref<'approve' | 'deny'>('approve')
const dialogRequest = ref<McpApprovalRow | null>(null)
const submitting = ref(false)
const notice = ref<{ tone: 'success' | 'warning' | 'error', text: string } | null>(null)
const refreshButton = ref<HTMLButtonElement | null>(null)
let decisionTrigger: HTMLElement | null = null

onMounted(() => {
  if (canView.value) fetchFirstPage()
})

function openDecision(request: McpApprovalRow, decision: 'approve' | 'deny', event?: Event): void {
  decisionTrigger = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null
  dialogRequest.value = request
  dialogDecision.value = decision
  notice.value = null
  dialogOpen.value = true
}

/**
 * Return focus to the Approve/Deny button that opened the dialog; when a
 * refetch removed that row, fall back to the always-present Refresh control.
 */
async function restoreDecisionFocus(): Promise<void> {
  await nextTick()
  const trigger = decisionTrigger
  decisionTrigger = null
  if (trigger?.isConnected) {
    trigger.focus()
    return
  }
  refreshButton.value?.focus()
}

async function confirmDecision(reason: string): Promise<void> {
  const request = dialogRequest.value
  if (!request || submitting.value) return
  submitting.value = true
  try {
    const result = await decide(request.id, dialogDecision.value, reason)
    dialogOpen.value = false
    if (result.ok) {
      notice.value = { tone: 'success', text: t('mcp_approvals_decided') }
      await refresh()
    } else if (result.kind === 'conflict' || result.kind === 'not-found') {
      // The request was decided, consumed, or expired elsewhere — refresh
      // honestly instead of pretending the local queue is still current.
      notice.value = { tone: 'warning', text: t('mcp_approvals_stale') }
      await refresh()
    } else {
      notice.value = {
        tone: 'error',
        text: t({
          invalid: 'mcp_approvals_error_invalid',
          forbidden: 'mcp_approvals_error_forbidden',
          unavailable: 'mcp_approvals_error_unavailable',
          network: 'mcp_approvals_error_network',
        }[result.kind]),
      }
    }
  } finally {
    submitting.value = false
  }
  await restoreDecisionFocus()
}

function cancelDecision(): void {
  dialogOpen.value = false
  void restoreDecisionFocus()
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
  <div class="p-6 approvals-page">
    <div class="approvals-header">
      <h1 class="text-2xl font-semibold">{{ t('mcp_approvals_title') }}</h1>
      <button
        v-if="canView && !forbidden"
        ref="refreshButton"
        type="button"
        class="btn"
        data-testid="approvals-refresh"
        :disabled="loading"
        @click="refresh"
      >
        {{ t('mcp_approvals_refresh') }}
      </button>
    </div>

    <div
      v-if="!canView || forbidden"
      class="alert-error mb-4"
      data-testid="approvals-forbidden"
      role="status"
    >
      {{ t('mcp_approvals_forbidden') }}
    </div>

    <template v-else>
      <p class="text-gray-500 mb-4">{{ t('mcp_approvals_intro') }}</p>

      <div
        v-if="notice"
        class="approvals-notice"
        :class="`approvals-notice--${notice.tone}`"
        data-testid="approvals-notice"
        role="status"
        aria-live="polite"
      >
        {{ notice.text }}
      </div>

      <div v-if="error" class="alert-error mb-4" data-testid="approvals-error" role="alert">{{ error }}</div>

      <div v-if="loading" class="text-gray-500" data-testid="approvals-loading">{{ t('loading') }}</div>

      <div
        v-else-if="requests.length > 0"
        class="approvals-table-wrap"
        data-testid="approvals-region"
        role="region"
        tabindex="0"
        :aria-label="t('mcp_approvals_title')"
      >
        <table class="w-full border-collapse" data-testid="approvals-table">
          <caption class="sr-only">{{ t('mcp_approvals_title') }}</caption>
          <thead>
            <tr class="border-b">
              <th class="text-left py-2 pr-4">{{ t('mcp_approvals_request_id') }}</th>
              <th class="text-left py-2 pr-4">{{ t('mcp_approvals_col_operation') }}</th>
              <th class="text-left py-2 pr-4">{{ t('mcp_approvals_col_surface') }}</th>
              <th class="text-left py-2 pr-4">{{ t('mcp_approvals_col_safe_arguments') }}</th>
              <th class="text-left py-2 pr-4">{{ t('mcp_approvals_col_requested') }}</th>
              <th class="text-left py-2 pr-4">{{ t('mcp_approvals_col_expires') }}</th>
              <th class="text-left py-2 pr-4">{{ t('mcp_approvals_col_principal') }}</th>
              <th v-if="canDecide" class="text-left py-2">{{ t('actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="request in requests"
              :key="request.id"
              class="border-b align-top"
              data-testid="approval-row"
            >
              <td class="py-2 pr-4"><code class="approvals-code">{{ request.id }}</code></td>
              <td class="py-2 pr-4">{{ request.operation }}</td>
              <td class="py-2 pr-4">{{ request.surface }}</td>
              <td class="py-2 pr-4"><code class="approvals-code">{{ JSON.stringify(request.safeArguments) }}</code></td>
              <td class="py-2 pr-4">
                <time :datetime="request.requestedAt">{{ formatDate(request.requestedAt) }}</time>
              </td>
              <td class="py-2 pr-4">
                <time :datetime="request.expiresAt">{{ formatDate(request.expiresAt) }}</time>
              </td>
              <td class="py-2 pr-4">{{ request.principal }}</td>
              <td v-if="canDecide" class="py-2">
                <div class="approvals-actions">
                  <button
                    type="button"
                    class="btn btn--primary btn-sm"
                    data-testid="approval-approve"
                    @click="openDecision(request, 'approve', $event)"
                  >
                    {{ t('mcp_approvals_approve') }}
                  </button>
                  <button
                    type="button"
                    class="btn btn--danger btn-sm"
                    data-testid="approval-deny"
                    @click="openDecision(request, 'deny', $event)"
                  >
                    {{ t('mcp_approvals_deny') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-else class="text-gray-500" data-testid="approvals-empty">{{ t('mcp_approvals_empty') }}</p>

      <div class="approvals-pagination">
        <button
          type="button"
          class="btn"
          data-testid="approvals-previous"
          :disabled="!hasPrevious || loading"
          @click="previousPage"
        >
          {{ t('previous') }}
        </button>
        <button
          type="button"
          class="btn"
          data-testid="approvals-next"
          :disabled="!hasNext || loading"
          @click="nextPage"
        >
          {{ t('next') }}
        </button>
      </div>
    </template>

    <McpApprovalDecisionDialog
      :open="dialogOpen"
      :decision="dialogDecision"
      :request="dialogRequest"
      :submitting="submitting"
      @confirm="confirmDecision"
      @cancel="cancelDecision"
    />
  </div>
</template>

<style scoped>
.approvals-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}
.approvals-notice {
  margin-bottom: 1rem;
  padding: 0.625rem 0.875rem;
  border-radius: 0.375rem;
  font-size: 0.875rem;
  border: 1px solid transparent;
}
.approvals-notice--success {
  background: #ecfdf5;
  border-color: #059669;
  color: #065f46;
}
.approvals-notice--warning {
  background: #fffbeb;
  border-color: #b45309;
  color: #92400e;
}
.approvals-notice--error {
  background: #fef2f2;
  border-color: #b42318;
  color: #991b1b;
}
.approvals-table-wrap { overflow-x: auto; }
.approvals-code {
  font-size: 0.8125rem;
  overflow-wrap: anywhere;
}
.approvals-actions { display: flex; gap: 0.5rem; }
.approvals-pagination {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 1rem;
}
.btn--danger { background: #b42318; color: #fff; }
</style>

<script setup lang="ts">
import { useQueueJobs, isFailedJob } from '~/composables/useQueueJobs'
import { useLanguage } from '~/composables/useLanguage'
import type { FailedJob, QueueJob, QueueStatusFilter } from '~/composables/useQueueJobs'

const { t } = useLanguage()
const { jobs, meta, loading, error, status, fetchJobs, retryJob, discardJob } = useQueueJobs()

onMounted(() => fetchJobs(1, 20, 'failed'))

const viewing = ref<FailedJob | null>(null)
const pendingDiscard = ref<FailedJob | null>(null)

const filters: QueueStatusFilter[] = ['failed', 'queued', 'in_progress', 'all']

function statusLabel(value: QueueStatusFilter): string {
  switch (value) {
    case 'failed': return t('queue_status_failed')
    case 'queued': return t('queue_status_queued')
    case 'in_progress': return t('queue_status_in_progress')
    case 'all': return t('queue_status_all')
  }
}

async function switchStatus(next: QueueStatusFilter): Promise<void> {
  if (next === status.value) {
    return
  }
  await fetchJobs(1, meta.value.per_page, next)
}

function openPayload(job: QueueJob): void {
  if (isFailedJob(job)) {
    viewing.value = job
  }
}

function closePayload(): void {
  viewing.value = null
}

async function onRetry(id: string): Promise<void> {
  try {
    await retryJob(id)
  } catch {
    // Error surfaced via composable state.
  }
}

function askDiscard(id: string): void {
  const candidate = jobs.value.find(j => j.id === id)
  pendingDiscard.value = candidate !== undefined && isFailedJob(candidate) ? candidate : null
}

function cancelDiscard(): void {
  pendingDiscard.value = null
}

async function confirmDiscard(): Promise<void> {
  if (pendingDiscard.value === null) {
    return
  }
  const id = pendingDiscard.value.id
  pendingDiscard.value = null
  try {
    await discardJob(id)
  } catch {
    // Error surfaced via composable state.
  }
}

const now = ref(Math.floor(Date.now() / 1000))
let nowTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  nowTimer = setInterval(() => {
    now.value = Math.floor(Date.now() / 1000)
  }, 5_000)
})

onBeforeUnmount(() => {
  if (nowTimer !== null) {
    clearInterval(nowTimer)
    nowTimer = null
  }
})

function ageSeconds(job: QueueJob): number {
  if (isFailedJob(job)) {
    return 0
  }
  // Transport row — show seconds since available_at (queued) or reserved_at (in_progress).
  const anchor = job.reserved_at ?? job.available_at
  return Math.max(0, now.value - anchor)
}

const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('queue_title')} | ${appName}`) })
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('queue_title') }}</h1>
    </div>

    <div class="filter-chips" data-testid="queue-filter-chips">
      <button
        v-for="f in filters"
        :key="f"
        type="button"
        class="chip"
        :class="{ active: status === f }"
        :data-testid="`queue-chip-${f}`"
        @click="switchStatus(f)"
      >
        {{ statusLabel(f) }}
      </button>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error" data-testid="queue-error">{{ error }}</div>

    <template v-else>
      <p v-if="jobs.length === 0" class="empty-state" data-testid="queue-empty">
        {{ t('queue_empty') }}
      </p>

      <!-- Failed-rows table (full M4B detail). -->
      <table
        v-else-if="status === 'failed'"
        class="entity-table"
        data-testid="queue-table"
      >
        <thead>
          <tr>
            <th>{{ t('queue_column_id') }}</th>
            <th>{{ t('queue_column_queue') }}</th>
            <th>{{ t('queue_column_exception_class') }}</th>
            <th>{{ t('queue_column_exception_message') }}</th>
            <th>{{ t('queue_column_failed_at') }}</th>
            <th>{{ t('queue_column_attempts') }}</th>
            <th>{{ t('actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="job in jobs" :key="job.id">
            <QueueJobRow
              v-if="isFailedJob(job)"
              :job="job"
              @retry="onRetry"
              @discard="askDiscard"
              @view="openPayload"
            />
          </template>
        </tbody>
      </table>

      <!-- Lean table for queued / in_progress / all (mixed). Retry/discard
           buttons only render on failed rows (C-001). -->
      <table v-else class="entity-table" data-testid="queue-table">
        <thead>
          <tr>
            <th>{{ t('queue_column_id') }}</th>
            <th>{{ t('queue_column_queue') }}</th>
            <th>{{ t('queue_column_status') }}</th>
            <th>{{ t('queue_column_attempts') }}</th>
            <th>{{ t('queue_column_age') }}</th>
            <th v-if="status === 'all'">{{ t('actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="job in jobs" :key="job.id" data-testid="queue-job-row">
            <td><code>{{ job.id }}</code></td>
            <td>{{ job.queue }}</td>
            <td>
              <span
                v-if="isFailedJob(job)"
                class="status-chip status-failed"
                data-testid="queue-row-status"
              >
                {{ t('queue_status_failed') }}
              </span>
              <span
                v-else
                class="status-chip"
                :class="`status-${job.status}`"
                data-testid="queue-row-status"
              >
                {{ statusLabel(job.status) }}
              </span>
            </td>
            <td>{{ job.attempts }}</td>
            <td>
              <span v-if="!isFailedJob(job)">
                {{ t('queue_age_seconds', { seconds: String(ageSeconds(job)) }) }}
              </span>
              <span v-else>—</span>
            </td>
            <td v-if="status === 'all'">
              <template v-if="isFailedJob(job)">
                <button
                  type="button"
                  class="btn"
                  data-testid="queue-job-retry"
                  @click="onRetry(job.id)"
                >
                  {{ t('queue_action_retry') }}
                </button>
                <button
                  type="button"
                  class="btn"
                  data-testid="queue-job-discard"
                  @click="askDiscard(job.id)"
                >
                  {{ t('queue_action_discard') }}
                </button>
              </template>
              <span v-else>—</span>
            </td>
          </tr>
        </tbody>
      </table>

      <p v-if="meta.total > 0" class="meta">
        {{ t('queue_pagination_summary', {
          page: String(meta.page),
          perPage: String(meta.per_page),
          total: String(meta.total),
        }) }}
      </p>
    </template>

    <QueuePayloadModal
      v-if="viewing"
      :job-id="viewing.id"
      :payload="viewing.payload"
      :payload-truncated="viewing.payload_truncated"
      @close="closePayload"
    />

    <div
      v-if="pendingDiscard"
      class="modal-overlay"
      data-testid="queue-discard-modal"
      @click.self="cancelDiscard"
    >
      <div class="modal" role="dialog" aria-modal="true">
        <h2>{{ t('queue_confirm_discard_title') }}</h2>
        <p>{{ t('queue_confirm_discard_body', { id: pendingDiscard.id }) }}</p>
        <div class="modal-actions">
          <button type="button" class="btn" data-testid="queue-discard-cancel" @click="cancelDiscard">
            {{ t('cancel') }}
          </button>
          <button
            type="button"
            class="btn"
            data-testid="queue-discard-confirm"
            @click="confirmDiscard"
          >
            {{ t('queue_action_discard') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.meta {
  margin-top: 12px;
  color: var(--color-muted);
  font-size: 13px;
}
.filter-chips {
  display: flex;
  gap: 6px;
  margin-bottom: 12px;
}
.chip {
  appearance: none;
  border: 1px solid var(--color-border, #d1d5db);
  background: var(--color-surface, #fff);
  color: var(--color-text, #111827);
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 13px;
  cursor: pointer;
}
.chip.active {
  background: var(--color-primary, #0f766e);
  color: #fff;
  border-color: var(--color-primary, #0f766e);
}
.status-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
}
.status-chip.status-queued {
  background: #dbeafe;
  color: #1e40af;
}
.status-chip.status-in_progress {
  background: #fef3c7;
  color: #92400e;
}
.status-chip.status-failed {
  background: #fee2e2;
  color: #991b1b;
}
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
}
.modal {
  background: var(--color-surface);
  border-radius: 6px;
  padding: 20px 24px;
  max-width: 420px;
  width: 100%;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
}
.modal h2 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 8px;
}
.modal p {
  margin-bottom: 16px;
}
.modal-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
</style>

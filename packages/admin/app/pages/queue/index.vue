<script setup lang="ts">
import { useQueueJobs } from '~/composables/useQueueJobs'
import { useLanguage } from '~/composables/useLanguage'
import type { FailedJob } from '~/composables/useQueueJobs'

const { t } = useLanguage()
const { jobs, meta, loading, error, fetchJobs, retryJob, discardJob } = useQueueJobs()

onMounted(() => fetchJobs())

const viewing = ref<FailedJob | null>(null)
const pendingDiscard = ref<FailedJob | null>(null)

function openPayload(job: FailedJob): void {
  viewing.value = job
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
  pendingDiscard.value = jobs.value.find(j => j.id === id) ?? null
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

const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('queue_title')} | ${appName}`) })
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('queue_title') }}</h1>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error" data-testid="queue-error">{{ error }}</div>

    <template v-else>
      <p v-if="jobs.length === 0" class="empty-state" data-testid="queue-empty">
        {{ t('queue_empty') }}
      </p>

      <table v-else class="entity-table" data-testid="queue-table">
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
          <QueueJobRow
            v-for="job in jobs"
            :key="job.id"
            :job="job"
            @retry="onRetry"
            @discard="askDiscard"
            @view="openPayload"
          />
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

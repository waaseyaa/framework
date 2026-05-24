<script setup lang="ts">
import { useNotificationChannels } from '~/composables/useNotificationChannels'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const {
  channels,
  loading,
  error,
  lastTestResult,
  fetchChannels,
  testChannel,
} = useNotificationChannels()

onMounted(() => fetchChannels())

const pendingTest = ref<string | null>(null)

function askTest(type: string): void {
  pendingTest.value = type
}

function cancelTest(): void {
  pendingTest.value = null
}

async function confirmTest(): Promise<void> {
  if (pendingTest.value === null) {
    return
  }
  const type = pendingTest.value
  pendingTest.value = null
  await testChannel(type)
}

const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('notifications_title')} | ${appName}`) })
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('notifications_title') }}</h1>
    </div>

    <p class="help" data-testid="notifications-help">{{ t('notifications_help') }}</p>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error" data-testid="notifications-error">{{ error }}</div>

    <template v-else>
      <p v-if="channels.length === 0" class="empty-state" data-testid="notifications-empty">
        {{ t('notifications_empty') }}
      </p>

      <table v-else class="entity-table" data-testid="notifications-table">
        <thead>
          <tr>
            <th>{{ t('notifications_column_type') }}</th>
            <th>{{ t('notifications_column_class') }}</th>
            <th>{{ t('notifications_column_action') }}</th>
          </tr>
        </thead>
        <tbody>
          <NotificationChannelRow
            v-for="channel in channels"
            :key="channel.type"
            :channel="channel"
            @test="askTest"
          />
        </tbody>
      </table>

      <div
        v-if="lastTestResult && lastTestResult.status === 'success'"
        class="result-chip result-chip--success"
        data-testid="notifications-result-success"
      >
        <strong>{{ t('notifications_status_success') }}</strong>
        — {{ lastTestResult.type }}: {{ lastTestResult.message }}
      </div>

      <div
        v-if="lastTestResult && lastTestResult.status === 'failed'"
        class="result-card result-card--failure"
        data-testid="notifications-result-failure"
      >
        <h2>{{ t('notifications_status_failure') }}</h2>
        <p><strong>{{ lastTestResult.type }}</strong>: {{ lastTestResult.message }}</p>
        <p v-if="lastTestResult.exception_class" class="result-card__class">
          <code>{{ lastTestResult.exception_class }}</code>
        </p>
      </div>
    </template>

    <div
      v-if="pendingTest"
      class="modal-overlay"
      data-testid="notifications-confirm-modal"
      @click.self="cancelTest"
    >
      <div class="modal" role="dialog" aria-modal="true">
        <h2>{{ t('notifications_confirm_test_title') }}</h2>
        <p>{{ t('notifications_confirm_test_body', { type: pendingTest }) }}</p>
        <div class="modal-actions">
          <button type="button" class="btn" data-testid="notifications-confirm-cancel" @click="cancelTest">
            {{ t('cancel') }}
          </button>
          <button
            type="button"
            class="btn"
            data-testid="notifications-confirm-send"
            @click="confirmTest"
          >
            {{ t('notifications_action_test') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.help {
  margin-bottom: 12px;
  color: var(--color-muted);
  font-size: 13px;
}
.result-chip {
  display: inline-block;
  margin-top: 12px;
  padding: 8px 12px;
  border-radius: 4px;
  font-size: 13px;
}
.result-chip--success {
  background: var(--color-success-bg, #d1fae5);
  color: var(--color-success, #065f46);
}
.result-card {
  margin-top: 12px;
  padding: 12px 16px;
  border-left: 3px solid var(--color-danger, #c00);
  background: var(--color-danger-bg, #fef2f2);
}
.result-card h2 {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 6px;
}
.result-card__class {
  font-size: 12px;
  color: var(--color-muted);
  margin-top: 6px;
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

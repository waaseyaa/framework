<script setup lang="ts">
import { useScheduledTasks } from '~/composables/useScheduledTasks'
import { useLanguage } from '~/composables/useLanguage'
import type { ScheduledTask } from '~/composables/useScheduledTasks'

const { t } = useLanguage()
const { tasks, loading, error, fetchTasks, triggerTask } = useScheduledTasks()

onMounted(() => fetchTasks())

const pendingTrigger = ref<ScheduledTask | null>(null)

function askTrigger(name: string): void {
  pendingTrigger.value = tasks.value.find(taskItem => taskItem.name === name) ?? null
}

function cancelTrigger(): void {
  pendingTrigger.value = null
}

async function confirmTrigger(): Promise<void> {
  if (pendingTrigger.value === null) {
    return
  }
  const name = pendingTrigger.value.name
  pendingTrigger.value = null
  try {
    await triggerTask(name)
  } catch {
    // Error surfaced via composable state.
  }
}

const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('scheduler_title')} | ${appName}`) })
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('scheduler_title') }}</h1>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error" data-testid="scheduler-error">{{ error }}</div>

    <template v-else>
      <p v-if="tasks.length === 0" class="empty-state" data-testid="scheduler-empty">
        {{ t('scheduler_empty') }}
      </p>

      <table v-else class="entity-table" data-testid="scheduler-table">
        <thead>
          <tr>
            <th>{{ t('scheduler_column_name') }}</th>
            <th>{{ t('scheduler_column_description') }}</th>
            <th>{{ t('scheduler_column_expression') }}</th>
            <th>{{ t('scheduler_column_timezone') }}</th>
            <th>{{ t('scheduler_column_last_run_at') }}</th>
            <th>{{ t('scheduler_column_last_status') }}</th>
            <th>{{ t('scheduler_column_next_run_at') }}</th>
            <th>{{ t('actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <SchedulerTaskRow
            v-for="task in tasks"
            :key="task.name"
            :task="task"
            @trigger="askTrigger"
          />
        </tbody>
      </table>
    </template>

    <div
      v-if="pendingTrigger"
      class="modal-overlay"
      data-testid="scheduler-trigger-modal"
      @click.self="cancelTrigger"
    >
      <div class="modal" role="dialog" aria-modal="true">
        <h2>{{ t('scheduler_confirm_trigger_title') }}</h2>
        <p>{{ t('scheduler_confirm_trigger_body', { name: pendingTrigger.name }) }}</p>
        <div class="modal-actions">
          <button
            type="button"
            class="btn"
            data-testid="scheduler-trigger-cancel"
            @click="cancelTrigger"
          >
            {{ t('cancel') }}
          </button>
          <button
            type="button"
            class="btn"
            data-testid="scheduler-trigger-confirm"
            @click="confirmTrigger"
          >
            {{ t('scheduler_action_trigger') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
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

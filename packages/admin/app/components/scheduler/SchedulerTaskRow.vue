<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import type { ScheduledTask } from '~/composables/useScheduledTasks'

defineProps<{ task: ScheduledTask }>()

const emit = defineEmits<{
  trigger: [name: string]
}>()

const { t } = useLanguage()

function statusLabel(status: string | null): string {
  if (status === null) {
    return t('scheduler_status_never')
  }
  if (status === 'success') {
    return t('scheduler_status_success')
  }
  if (status.startsWith('failed')) {
    return t('scheduler_status_failed')
  }
  if (status.startsWith('skipped')) {
    return t('scheduler_status_skipped')
  }

  return status
}

function statusClass(status: string | null): string {
  if (status === null) {
    return 'status-pill status-pill--never'
  }
  if (status === 'success') {
    return 'status-pill status-pill--success'
  }
  if (status.startsWith('failed')) {
    return 'status-pill status-pill--failed'
  }
  if (status.startsWith('skipped')) {
    return 'status-pill status-pill--skipped'
  }

  return 'status-pill'
}
</script>

<template>
  <tr data-testid="scheduler-task-row">
    <td><code>{{ task.name }}</code></td>
    <td>{{ task.description ?? '' }}</td>
    <td><code>{{ task.expression }}</code></td>
    <td>{{ task.timezone ?? '' }}</td>
    <td>{{ task.last_run_at ?? '' }}</td>
    <td>
      <span :class="statusClass(task.last_status)" :title="task.last_status ?? ''">
        {{ statusLabel(task.last_status) }}
      </span>
    </td>
    <td>{{ task.next_run_at }}</td>
    <td class="actions">
      <button
        type="button"
        class="btn"
        data-testid="scheduler-task-trigger"
        @click="emit('trigger', task.name)"
      >
        {{ t('scheduler_action_trigger') }}
      </button>
    </td>
  </tr>
</template>

<style scoped>
.actions {
  display: flex;
  gap: 6px;
}
.status-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 500;
  background: var(--color-bg);
  color: var(--color-muted);
}
.status-pill--success {
  background: #d1fae5;
  color: #065f46;
}
.status-pill--failed {
  background: #fee2e2;
  color: #991b1b;
}
.status-pill--skipped {
  background: #fef3c7;
  color: #92400e;
}
.status-pill--never {
  background: var(--color-bg);
  color: var(--color-muted);
}
</style>

<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import type { FailedJob } from '~/composables/useQueueJobs'

defineProps<{ job: FailedJob }>()

const emit = defineEmits<{
  retry: [id: string]
  discard: [id: string]
  view: [job: FailedJob]
}>()

const { t } = useLanguage()

function shortExceptionClass(fqcn: string): string {
  if (fqcn === '') {
    return ''
  }
  const idx = fqcn.lastIndexOf('\\')

  return idx === -1 ? fqcn : fqcn.slice(idx + 1)
}

function shortMessage(msg: string, maxLen = 120): string {
  return msg.length > maxLen ? `${msg.slice(0, maxLen)}…` : msg
}
</script>

<template>
  <tr data-testid="queue-job-row">
    <td><code>{{ job.id }}</code></td>
    <td>{{ job.queue }}</td>
    <td><code>{{ shortExceptionClass(job.exception_class) }}</code></td>
    <td :title="job.exception_message">{{ shortMessage(job.exception_message) }}</td>
    <td>{{ job.failed_at }}</td>
    <td>{{ job.attempts }}</td>
    <td class="actions">
      <button
        type="button"
        class="btn"
        data-testid="queue-job-retry"
        @click="emit('retry', job.id)"
      >
        {{ t('queue_action_retry') }}
      </button>
      <button
        type="button"
        class="btn"
        data-testid="queue-job-discard"
        @click="emit('discard', job.id)"
      >
        {{ t('queue_action_discard') }}
      </button>
      <button
        type="button"
        class="btn"
        data-testid="queue-job-view"
        @click="emit('view', job)"
      >
        {{ t('queue_action_view') }}
      </button>
    </td>
  </tr>
</template>

<style scoped>
.actions {
  display: flex;
  gap: 6px;
}
</style>

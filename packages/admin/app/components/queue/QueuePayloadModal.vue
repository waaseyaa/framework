<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

defineProps<{
  jobId: string
  payload: string
  payloadTruncated: boolean
}>()

const emit = defineEmits<{ close: [] }>()

const { t } = useLanguage()

function close() {
  emit('close')
}
</script>

<template>
  <div class="modal-overlay" data-testid="queue-payload-modal" @click.self="close">
    <div class="modal" role="dialog" aria-modal="true" :aria-label="t('queue_payload_title')">
      <div class="modal-header">
        <h2>{{ t('queue_payload_title') }} — <code>{{ jobId }}</code></h2>
        <button type="button" class="btn" data-testid="queue-payload-close" @click="close">
          {{ t('close') }}
        </button>
      </div>
      <p v-if="payloadTruncated" class="warning">
        {{ t('queue_payload_truncated') }}
      </p>
      <pre class="payload" data-testid="queue-payload-content">{{ payload }}</pre>
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
  padding: 24px;
}
.modal {
  background: var(--color-surface);
  border-radius: 6px;
  padding: 16px 20px;
  max-width: 800px;
  width: 100%;
  max-height: 80vh;
  overflow: auto;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
}
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.modal-header h2 {
  font-size: 16px;
  font-weight: 600;
}
.warning {
  background: #fef3c7;
  color: #92400e;
  border-radius: 4px;
  padding: 8px 12px;
  font-size: 13px;
  margin-bottom: 8px;
}
.payload {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, monospace;
  font-size: 12px;
  background: #f5f5f5;
  padding: 12px;
  border-radius: 4px;
  white-space: pre-wrap;
  word-break: break-all;
  margin: 0;
}
</style>

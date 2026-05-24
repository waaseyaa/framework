<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import type { NotificationChannel } from '~/composables/useNotificationChannels'

defineProps<{ channel: NotificationChannel }>()

const emit = defineEmits<{
  test: [type: string]
}>()

const { t } = useLanguage()

function shortClass(fqcn: string): string {
  if (fqcn === '') {
    return ''
  }
  const idx = fqcn.lastIndexOf('\\')

  return idx === -1 ? fqcn : fqcn.slice(idx + 1)
}
</script>

<template>
  <tr data-testid="notification-channel-row">
    <td><code>{{ channel.type }}</code></td>
    <td :title="channel.class">
      <code>{{ shortClass(channel.class) }}</code>
    </td>
    <td class="actions">
      <button
        type="button"
        class="btn"
        data-testid="notification-channel-test"
        @click="emit('test', channel.type)"
      >
        {{ t('notifications_action_test') }}
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

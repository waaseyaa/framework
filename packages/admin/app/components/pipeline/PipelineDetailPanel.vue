<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

const props = defineProps<{
  entityType: string
  entityId: string | null
}>()

const emit = defineEmits<{
  close: []
}>()

const { t } = useLanguage()

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Transition name="panel-slide">
    <aside
      v-if="entityId"
      class="detail-panel"
      role="complementary"
      :aria-label="t('view_entity', { type: entityType })"
    >
      <div class="panel-header">
        <h2>{{ entityType }} #{{ entityId }}</h2>
        <div class="panel-actions">
          <NuxtLink
            :to="`/${entityType}/${entityId}`"
            class="btn btn-sm"
          >
            {{ t('edit') }}
          </NuxtLink>
          <button
            class="btn btn-sm"
            :aria-label="t('close')"
            @click="emit('close')"
          >
            &times;
          </button>
        </div>
      </div>
      <div class="panel-body">
        <SchemaView
          :key="entityId"
          :entity-type="entityType"
          :entity-id="entityId"
        />
      </div>
    </aside>
  </Transition>
</template>

<style scoped>
.detail-panel {
  position: fixed;
  top: 0;
  right: 0;
  width: 420px;
  max-width: 90vw;
  height: 100vh;
  background: var(--color-surface, #fff);
  border-left: 1px solid var(--color-border, #e2e8f0);
  box-shadow: -4px 0 24px rgba(0, 0, 0, 0.08);
  z-index: 100;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid var(--color-border, #e2e8f0);
  flex-shrink: 0;
}

.panel-header h2 {
  font-size: 16px;
  font-weight: 600;
  margin: 0;
}

.panel-actions {
  display: flex;
  gap: 8px;
  align-items: center;
}

.btn-sm {
  padding: 4px 10px;
  font-size: 13px;
}

.panel-body {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
}

.panel-slide-enter-active,
.panel-slide-leave-active {
  transition: transform 0.2s ease;
}

.panel-slide-enter-from,
.panel-slide-leave-to {
  transform: translateX(100%);
}
</style>

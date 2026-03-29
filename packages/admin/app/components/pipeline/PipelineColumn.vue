<script setup lang="ts">
import type { PipelineCard, BoardConfig, CardDensity } from '~/composables/useEntityPipeline'

defineProps<{
  stage: string
  cardIds: string[]
  cards: Map<string, PipelineCard>
  config: BoardConfig
  density: CardDensity
}>()

const emit = defineEmits<{
  drop: [cardId: string, toStage: string]
  'open-detail': [id: string]
  'run-action': [action: string, payload: Record<string, unknown>]
}>()

const dragOver = ref(false)

function capitalize(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/[_-]/g, ' ')
}

function onDragOver(event: DragEvent) {
  event.preventDefault()
  dragOver.value = true
}

function onDragLeave() {
  dragOver.value = false
}

function onDrop(event: DragEvent, stage: string) {
  event.preventDefault()
  dragOver.value = false
  const cardId = event.dataTransfer?.getData('text/plain')
  if (cardId) {
    emit('drop', cardId, stage)
  }
}
</script>

<template>
  <div
    class="pipeline-column"
    :class="{ 'drag-over': dragOver }"
    @dragover="onDragOver"
    @dragleave="onDragLeave"
    @drop="onDrop($event, stage)"
  >
    <div class="column-header">
      <span class="column-title">{{ capitalize(stage) }}</span>
      <span class="column-count">{{ cardIds.length }}</span>
    </div>

    <div class="column-cards">
      <template v-for="cardId in cardIds" :key="cardId">
        <PipelineCard
          v-if="cards.get(cardId)"
          :card="cards.get(cardId)!"
          :density="density"
          @open-detail="emit('open-detail', $event)"
          @run-action="(action, payload) => emit('run-action', action, payload)"
        />
      </template>

      <div v-if="cardIds.length === 0" class="empty-state">
        No leads
      </div>
    </div>
  </div>
</template>

<style scoped>
.pipeline-column {
  flex: 0 0 300px;
  width: 300px;
  max-width: 300px;
  min-height: 400px;
  background: var(--color-bg, #f8fafc);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: border-color 0.15s ease;
}

.pipeline-column.drag-over {
  border-color: var(--color-primary, #3b82f6);
  background: var(--color-primary-light, #eff6ff);
}

.column-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  border-bottom: 1px solid var(--color-border);
}

.column-title {
  font-weight: 600;
  font-size: 14px;
}

.column-count {
  font-size: 12px;
  font-weight: 600;
  background: var(--color-border);
  color: var(--color-text-muted, #64748b);
  padding: 2px 8px;
  border-radius: 999px;
}

.column-cards {
  flex: 1;
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  overflow-y: auto;
}

.empty-state {
  text-align: center;
  color: var(--color-text-muted, #94a3b8);
  font-size: 13px;
  padding: 24px 0;
}
</style>

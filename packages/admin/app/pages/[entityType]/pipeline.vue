<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useEntityPipeline } from '~/composables/useEntityPipeline'

const route = useRoute()
const { entityLabel: translateEntityLabel } = useLanguage()

const entityType = computed(() => route.params.entityType as string)
const pipeline = useEntityPipeline()

const entityLabel = computed(() => translateEntityLabel(entityType.value, entityType.value))
const runtimeConfig = useRuntimeConfig()
useHead({ title: computed(() => `${entityLabel.value} Pipeline | ${runtimeConfig.public.appName}`) })

const defaultHiddenStages = ['won', 'lost']
const visibleStages = computed(() => {
  if (!pipeline.config.value) return []
  return pipeline.config.value.stages.filter(
    (s) => !defaultHiddenStages.includes(s),
  )
})

function onDrop(cardId: string, toStage: string) {
  pipeline.moveCard(entityType.value, cardId, toStage)
}

function onOpenDetail(id: string) {
  window.open(`/${entityType.value}/${id}`, '_blank')
}

onMounted(() => {
  pipeline.loadBoard(entityType.value)
})

watch(entityType, () => {
  pipeline.loadBoard(entityType.value)
})
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ entityLabel }} Pipeline</h1>
    </div>

    <EntityViewNav :entity-type="entityType" />

    <div v-if="pipeline.loading.value" class="loading">
      Loading pipeline...
    </div>

    <div v-else-if="pipeline.error.value" class="error">
      <template v-if="pipeline.error.value.includes('board-config') || !pipeline.config.value">
        Pipeline is not configured for this entity type.
      </template>
      <template v-else>
        {{ pipeline.error.value }}
      </template>
    </div>

    <div v-else-if="pipeline.config.value" class="pipeline-board">
      <PipelineColumn
        v-for="stage in visibleStages"
        :key="stage"
        :stage="stage"
        :card-ids="pipeline.columns.value.get(stage) ?? []"
        :cards="pipeline.cards.value"
        :config="pipeline.config.value"
        density="detailed"
        @drop="onDrop"
        @open-detail="onOpenDetail"
        @run-action="(action: string, payload: Record<string, unknown>) => pipeline.runCardAction(entityType.value, action, payload)"
      />
    </div>
  </div>
</template>

<style scoped>
.pipeline-board {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding-bottom: 16px;
  align-items: flex-start;
}

.pipeline-board > * {
  flex: 0 0 300px;
  max-width: 300px;
  min-width: 280px;
}

.loading {
  text-align: center;
  color: var(--color-text-muted, #64748b);
  padding: 48px 0;
  font-size: 14px;
}
</style>

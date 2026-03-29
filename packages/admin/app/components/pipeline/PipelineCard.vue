<script setup lang="ts">
import type { PipelineCard, CardDensity } from '~/composables/useEntityPipeline'

const props = defineProps<{
  card: PipelineCard
  density: CardDensity
}>()

const emit = defineEmits<{
  'open-detail': [id: string]
  'run-action': [action: string, payload: Record<string, unknown>]
}>()

const ratingColor = computed(() => {
  const rating = Number(props.card.attributes.qualify_rating ?? 0)
  if (rating >= 70) return 'var(--color-success, #16a34a)'
  if (rating >= 40) return 'var(--color-warning, #d97706)'
  return 'var(--color-danger, #dc2626)'
})

const daysRemaining = computed(() => {
  const closing = props.card.attributes.closing_date
  if (!closing) return null
  const diff = new Date(closing as string).getTime() - Date.now()
  return Math.ceil(diff / (1000 * 60 * 60 * 24))
})

const formattedValue = computed(() => {
  const val = props.card.attributes.value
  if (val == null) return null
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(val))
})

function onDragStart(event: DragEvent) {
  event.dataTransfer?.setData('text/plain', props.card.id)
}
</script>

<template>
  <div
    class="pipeline-card"
    :class="`density-${density}`"
    draggable="true"
    @dragstart="onDragStart"
    @click="emit('open-detail', card.id)"
  >
    <div class="card-header">
      <span class="card-label">{{ card.label }}</span>
      <span
        v-if="density !== 'compact' && card.attributes.qualify_rating != null"
        class="score-badge"
        :style="{ backgroundColor: ratingColor }"
      >
        {{ card.attributes.qualify_rating }}
      </span>
    </div>

    <template v-if="density === 'detailed' || density === 'standard'">
      <div v-if="card.attributes.company_name" class="card-company">
        {{ card.attributes.company_name }}
        <template v-if="card.attributes.contact_name">
          &mdash; {{ card.attributes.contact_name }}
        </template>
      </div>
    </template>

    <template v-if="density === 'detailed'">
      <div v-if="card.attributes.contact_email" class="card-email">
        {{ card.attributes.contact_email }}
      </div>

      <div class="card-tags">
        <span v-if="card.attributes.source" class="tag">{{ card.attributes.source }}</span>
        <span v-if="card.attributes.sector" class="tag">{{ card.attributes.sector }}</span>
        <span v-if="formattedValue" class="tag tag-value">{{ formattedValue }}</span>
      </div>

      <div v-if="daysRemaining !== null" class="card-footer">
        <span :class="['urgency', { overdue: daysRemaining < 0 }]">
          {{ daysRemaining < 0 ? `${Math.abs(daysRemaining)}d overdue` : `${daysRemaining}d remaining` }}
        </span>
      </div>
    </template>
  </div>
</template>

<style scoped>
.pipeline-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 10px 12px;
  cursor: grab;
  transition: box-shadow 0.15s ease;
}

.pipeline-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

.pipeline-card:active {
  cursor: grabbing;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.card-label {
  font-weight: 600;
  font-size: 14px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.score-badge {
  flex-shrink: 0;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 999px;
  min-width: 28px;
  text-align: center;
}

.card-company {
  font-size: 12px;
  color: var(--color-text-muted, #64748b);
  margin-top: 4px;
}

.card-email {
  font-size: 11px;
  color: var(--color-text-muted, #64748b);
  margin-top: 2px;
}

.card-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 6px;
}

.tag {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 4px;
  background: var(--color-bg, #f1f5f9);
  color: var(--color-text-muted, #475569);
}

.tag-value {
  font-weight: 600;
}

.card-footer {
  margin-top: 6px;
  font-size: 11px;
}

.urgency {
  color: var(--color-text-muted, #64748b);
}

.urgency.overdue {
  color: var(--color-danger, #dc2626);
  font-weight: 600;
}

.density-compact {
  padding: 6px 10px;
}

.density-compact .card-label {
  font-size: 13px;
}
</style>

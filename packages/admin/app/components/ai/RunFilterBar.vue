<script setup lang="ts">
import type { RunListFilter } from '~/composables/useAiObservabilityRuns'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()

interface Props {
  modelValue: RunListFilter
}

const props = defineProps<Props>()
const emit = defineEmits<{
  'update:modelValue': [value: RunListFilter]
}>()

const pipeline = ref(props.modelValue.pipeline ?? '')
const filterStatus = ref(props.modelValue.status ?? '')
const from = ref(props.modelValue.from ?? '')
const to = ref(props.modelValue.to ?? '')

function apply(): void {
  emit('update:modelValue', {
    pipeline: pipeline.value || null,
    status: filterStatus.value || null,
    from: from.value || null,
    to: to.value || null,
  })
}

function clear(): void {
  pipeline.value = ''
  filterStatus.value = ''
  from.value = ''
  to.value = ''
  emit('update:modelValue', { pipeline: null, status: null, from: null, to: null })
}
</script>

<template>
  <div class="filter-bar" data-testid="run-filter-bar">
    <input
      v-model="pipeline"
      type="text"
      class="filter-input"
      :placeholder="t('ai_runs_filter_pipeline_placeholder')"
      data-testid="filter-pipeline"
      @keydown.enter="apply"
    >
    <select
      v-model="filterStatus"
      class="filter-select"
      data-testid="filter-status"
      @change="apply"
    >
      <option value="">{{ t('ai_runs_filter_status_all') }}</option>
      <option value="ok">ok</option>
      <option value="error">error</option>
      <option value="queued">queued</option>
    </select>
    <input
      v-model="from"
      type="date"
      class="filter-input"
      :aria-label="t('ai_runs_filter_from')"
      data-testid="filter-from"
    >
    <input
      v-model="to"
      type="date"
      class="filter-input"
      :aria-label="t('ai_runs_filter_to')"
      data-testid="filter-to"
    >
    <button type="button" class="btn" data-testid="filter-apply" @click="apply">
      {{ t('ai_runs_filter_apply') }}
    </button>
    <button type="button" class="btn btn--ghost" data-testid="filter-clear" @click="clear">
      {{ t('ai_runs_filter_clear') }}
    </button>
  </div>
</template>

<style scoped>
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
  align-items: center;
}
.filter-input,
.filter-select {
  padding: 6px 10px;
  border: 1px solid var(--color-border, #d1d5db);
  border-radius: 4px;
  font-size: 13px;
  background: var(--color-surface, #fff);
  color: var(--color-text, #111827);
}
.btn--ghost {
  background: transparent;
  border: 1px solid var(--color-border, #d1d5db);
  color: var(--color-text, #111827);
}
</style>

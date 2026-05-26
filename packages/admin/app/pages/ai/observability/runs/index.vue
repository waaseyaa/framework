<script setup lang="ts">
import { useAiObservabilityRuns } from '~/composables/useAiObservabilityRuns'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const { rows, total, page, perPage, filter, loading, error, fetchRuns, setFilter, setPage } = useAiObservabilityRuns()

onMounted(() => fetchRuns())

async function onPageChange(n: number): Promise<void> {
  setPage(n)
  await fetchRuns()
}

async function onFilterUpdate(val: typeof filter.value): Promise<void> {
  setFilter(val)
  await fetchRuns()
}

const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('ai_runs_title')} | ${appName}`) })
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('ai_runs_title') }}</h1>
    </div>

    <RunFilterBar
      :model-value="filter"
      data-testid="runs-filter-bar"
      @update:model-value="onFilterUpdate"
    />

    <div v-if="loading" class="loading" data-testid="runs-loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error" data-testid="runs-error">{{ error }}</div>

    <RunListTable
      v-else
      :rows="rows"
      :page="page"
      :per-page="perPage"
      :total="total"
      data-testid="runs-table"
      @page-change="onPageChange"
    />
  </div>
</template>

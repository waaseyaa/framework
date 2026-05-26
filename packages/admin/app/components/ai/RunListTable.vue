<script setup lang="ts">
import type { RunListRow } from '~/composables/useAiObservabilityRuns'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()

interface Props {
  rows: RunListRow[]
  page: number
  perPage: number
  total: number
}

defineProps<Props>()
const emit = defineEmits<{ 'page-change': [page: number] }>()
</script>

<template>
  <div>
    <table class="entity-table" data-testid="run-list-table">
      <thead>
        <tr>
          <th>{{ t('ai_runs_col_pipeline') }}</th>
          <th>{{ t('ai_runs_col_status') }}</th>
          <th>{{ t('ai_runs_col_started_at') }}</th>
          <th>{{ t('ai_runs_col_duration') }}</th>
          <th>{{ t('ai_runs_col_cost_usd') }}</th>
          <th>{{ t('ai_runs_col_total_tokens') }}</th>
          <th>{{ t('ai_runs_col_spans') }}</th>
          <th>{{ t('actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="rows.length === 0">
          <td colspan="8" class="empty-state" data-testid="run-list-empty">
            {{ t('ai_runs_empty') }}
          </td>
        </tr>
        <tr
          v-for="row in rows"
          :key="row.traceUuid"
          data-testid="run-list-row"
        >
          <td>{{ row.pipeline }}</td>
          <td>
            <span
              class="status-chip"
              :class="`status-${row.status}`"
              data-testid="run-row-status"
            >{{ row.status }}</span>
          </td>
          <td>{{ row.startedAt }}</td>
          <td>{{ row.durationMs !== null ? `${row.durationMs}ms` : '—' }}</td>
          <td>${{ row.costUsd.toFixed(4) }}</td>
          <td>{{ row.totalTokens }}</td>
          <td>{{ row.spanCount }}</td>
          <td>
            <NuxtLink
              :to="`/ai/observability/runs/${row.traceUuid}`"
              class="btn"
              data-testid="run-row-detail-link"
            >
              {{ t('ai_runs_action_detail') }}
            </NuxtLink>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="total > 0" class="pagination" data-testid="run-list-pagination">
      <button
        type="button"
        class="btn"
        :disabled="page <= 1"
        data-testid="run-pagination-prev"
        @click="emit('page-change', page - 1)"
      >
        {{ t('previous') }}
      </button>
      <span class="pagination-info">
        {{ t('showing') }} {{ (page - 1) * perPage + 1 }}–{{ Math.min(page * perPage, total) }} {{ t('of') }} {{ total }}
      </span>
      <button
        type="button"
        class="btn"
        :disabled="page * perPage >= total"
        data-testid="run-pagination-next"
        @click="emit('page-change', page + 1)"
      >
        {{ t('next') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.pagination {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 12px;
  font-size: 13px;
}
.pagination-info {
  color: var(--color-muted);
}
.status-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
}
.status-ok {
  background: #dcfce7;
  color: #166534;
}
.status-error {
  background: #fee2e2;
  color: #991b1b;
}
.status-queued {
  background: #dbeafe;
  color: #1e40af;
}
</style>

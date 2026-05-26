<script setup lang="ts">
import { useAiObservabilityRunDetail } from '~/composables/useAiObservabilityRunDetail'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const route = useRoute()
const uuid = computed(() => route.params.uuid as string)

const { run, loading, error, fetchRun, replay } = useAiObservabilityRunDetail()

const replaying = ref(false)
const replayError = ref<string | null>(null)
const replayResult = ref<{ newRunUuid: string, status: string } | null>(null)

onMounted(() => fetchRun(uuid.value))

async function onReplay(): Promise<void> {
  replaying.value = true
  replayError.value = null
  replayResult.value = null
  try {
    const result = await replay(uuid.value)
    replayResult.value = { newRunUuid: result.newRunUuid, status: result.status }
  } catch {
    replayError.value = error.value ?? t('ai_runs_replay_error')
  } finally {
    replaying.value = false
  }
}

const { appName } = useAdminConfig()
useHead({
  title: computed(() => {
    const label = run.value?.header?.pipeline ?? uuid.value
    return `${label} | ${t('ai_runs_title')} | ${appName}`
  }),
})
</script>

<template>
  <div>
    <div class="page-header">
      <NuxtLink to="/ai/observability/runs" class="back-link">
        ← {{ t('ai_runs_back') }}
      </NuxtLink>
      <h1>{{ run?.header?.pipeline ?? uuid }}</h1>
    </div>

    <div v-if="loading" class="loading" data-testid="run-detail-loading">{{ t('loading') }}</div>

    <div v-else-if="error && !run" class="error" data-testid="run-detail-error">{{ error }}</div>

    <template v-else-if="run">
      <!-- Run header summary -->
      <div class="run-summary" data-testid="run-detail-summary">
        <div class="run-summary-row">
          <span class="label">{{ t('ai_runs_col_status') }}</span>
          <span
            class="status-chip"
            :class="`status-${run.header.status}`"
            data-testid="run-detail-status"
          >{{ run.header.status }}</span>
        </div>
        <div class="run-summary-row">
          <span class="label">{{ t('ai_runs_col_started_at') }}</span>
          <span>{{ run.header.startedAt }}</span>
        </div>
        <div class="run-summary-row">
          <span class="label">{{ t('ai_runs_col_duration') }}</span>
          <span>{{ run.header.durationMs !== null ? `${run.header.durationMs}ms` : '—' }}</span>
        </div>
        <div class="run-summary-row">
          <span class="label">{{ t('ai_runs_col_cost_usd') }}</span>
          <span>${{ run.header.costUsd.toFixed(4) }}</span>
        </div>
        <div class="run-summary-row">
          <span class="label">{{ t('ai_runs_col_total_tokens') }}</span>
          <span>{{ run.header.totalTokens }}</span>
        </div>
        <div class="run-summary-row">
          <span class="label">{{ t('ai_runs_col_spans') }}</span>
          <span>{{ run.header.spanCount }}</span>
        </div>
      </div>

      <!-- Replay action -->
      <div class="replay-section" data-testid="run-detail-replay-section">
        <button
          type="button"
          class="btn"
          :disabled="replaying"
          data-testid="run-detail-replay-btn"
          @click="onReplay"
        >
          {{ replaying ? t('ai_runs_replaying') : t('ai_runs_replay') }}
        </button>
        <div v-if="replayResult" class="replay-success" data-testid="run-detail-replay-result">
          {{ t('ai_runs_replay_queued') }}: {{ replayResult.newRunUuid }}
        </div>
        <div v-if="replayError" class="error" data-testid="run-detail-replay-error">
          {{ replayError }}
        </div>
      </div>

      <!-- Span tree -->
      <div class="span-tree-section">
        <h2>{{ t('ai_runs_span_tree') }}</h2>
        <p v-if="run.spans.length === 0" class="empty-state" data-testid="run-detail-no-spans">
          {{ t('ai_runs_no_spans') }}
        </p>
        <div v-else data-testid="run-detail-spans">
          <RunSpanNode
            v-for="span in run.spans"
            :key="span.spanUuid"
            :span="span"
          />
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.back-link {
  font-size: 13px;
  color: var(--color-muted);
  text-decoration: none;
  display: inline-block;
  margin-bottom: 8px;
}
.back-link:hover {
  color: var(--color-primary);
}
.run-summary {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 6px 16px;
  margin-bottom: 20px;
  font-size: 13px;
}
.run-summary-row {
  display: contents;
}
.label {
  color: var(--color-muted);
  font-weight: 500;
}
.replay-section {
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.replay-success {
  font-size: 13px;
  color: #166534;
  background: #dcfce7;
  padding: 4px 10px;
  border-radius: 4px;
}
.span-tree-section {
  margin-top: 8px;
}
.span-tree-section h2 {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 8px;
}
.status-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
}
.status-ok { background: #dcfce7; color: #166534; }
.status-error { background: #fee2e2; color: #991b1b; }
.status-queued { background: #dbeafe; color: #1e40af; }
</style>

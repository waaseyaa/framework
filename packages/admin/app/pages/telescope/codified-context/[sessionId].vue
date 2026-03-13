<script setup lang="ts">
import { useCodifiedContext } from '~/composables/useCodifiedContext'
import { useLanguage } from '~/composables/useLanguage'
import DriftScoreChart from '~/components/telescope/DriftScoreChart.vue'
import EventStreamViewer from '~/components/telescope/EventStreamViewer.vue'
import ValidationReportCard from '~/components/telescope/ValidationReportCard.vue'
import ContextHeatmap from '~/components/telescope/ContextHeatmap.vue'

const { t } = useLanguage()
const route = useRoute()
const sessionId = computed(() => route.params.sessionId as string)

const {
  currentSession,
  events,
  validationReport,
  loading,
  error,
  fetchSession,
  fetchEvents,
  fetchValidation,
} = useCodifiedContext()

onMounted(async () => {
  await fetchSession(sessionId.value)
  await Promise.all([
    fetchEvents(sessionId.value),
    fetchValidation(sessionId.value),
  ])
})

const config = useRuntimeConfig()
useHead({ title: computed(() => `${t('telescope_codified_context')} | ${config.public.appName}`) })

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString()
}
</script>

<template>
  <div>
    <div class="page-header">
      <NuxtLink to="/telescope/codified-context" class="btn">
        ← {{ t('back_to_list') }}
      </NuxtLink>
      <h1>{{ t('telescope_codified_context') }}</h1>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>
    <div v-else-if="error" class="error">{{ error }}</div>

    <template v-else-if="currentSession">
      <section class="session-meta card">
        <h2>Session</h2>
        <dl class="meta-grid">
          <dt>Session ID</dt>
          <dd>{{ currentSession.sessionId }}</dd>
          <dt>Repo Hash</dt>
          <dd>{{ currentSession.repoHash }}</dd>
          <dt>Started</dt>
          <dd>{{ formatDate(currentSession.startedAt) }}</dd>
          <dt>Ended</dt>
          <dd>{{ currentSession.endedAt ? formatDate(currentSession.endedAt) : '(active)' }}</dd>
          <dt>Events</dt>
          <dd>{{ currentSession.eventCount }}</dd>
        </dl>
      </section>

      <section v-if="currentSession.latestDriftScore !== null" class="section">
        <h2>{{ t('telescope_cc_drift_score') }}</h2>
        <DriftScoreChart :score="currentSession.latestDriftScore" />
      </section>

      <section class="section">
        <h2>{{ t('telescope_cc_heatmap') }}</h2>
        <ContextHeatmap :events="events" />
      </section>

      <section class="section">
        <h2>{{ t('telescope_cc_validation') }}</h2>
        <ValidationReportCard :report="validationReport" />
      </section>

      <section class="section">
        <h2>{{ t('telescope_cc_events') }}</h2>
        <EventStreamViewer :events="events" />
      </section>
    </template>
  </div>
</template>

<style scoped>
.card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  padding: 1.25rem;
  margin-bottom: 1.5rem;
}
.section {
  margin-bottom: 2rem;
}
.meta-grid {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.25rem 1rem;
}
dt { font-weight: 600; color: #374151; }
dd { margin: 0; color: #6b7280; }
</style>

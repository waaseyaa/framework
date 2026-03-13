<script setup lang="ts">
import { useCodifiedContext } from '~/composables/useCodifiedContext'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const { sessions, loading, error, fetchSessions } = useCodifiedContext()

onMounted(() => fetchSessions())

const config = useRuntimeConfig()
useHead({ title: computed(() => `${t('telescope_codified_context')} | ${config.public.appName}`) })

function formatDuration(ms: number | null): string {
  if (ms === null) return '—'
  if (ms < 1000) return `${ms}ms`
  return `${(ms / 1000).toFixed(1)}s`
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString()
}

function severityClass(severity: string | null): string {
  switch (severity) {
    case 'critical': return 'badge badge--critical'
    case 'high': return 'badge badge--high'
    case 'medium': return 'badge badge--medium'
    case 'low': return 'badge badge--low'
    default: return 'badge badge--none'
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('telescope_codified_context') }}</h1>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error">{{ error }}</div>

    <template v-else>
      <p v-if="sessions.length === 0" class="empty-state">
        {{ t('telescope_cc_no_sessions') }}
      </p>

      <table v-else class="data-table">
        <thead>
          <tr>
            <th>{{ t('telescope_cc_sessions') }}</th>
            <th>Repo</th>
            <th>Started</th>
            <th>Duration</th>
            <th>Events</th>
            <th>{{ t('telescope_cc_drift_score') }}</th>
            <th>{{ t('telescope_cc_severity') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="session in sessions" :key="session.id">
            <td>
              <NuxtLink :to="`/telescope/codified-context/${session.sessionId}`">
                {{ session.sessionId.slice(0, 8) }}…
              </NuxtLink>
            </td>
            <td>{{ session.repoHash.slice(0, 8) }}</td>
            <td>{{ formatDate(session.startedAt) }}</td>
            <td>{{ formatDuration(session.durationMs) }}</td>
            <td>{{ session.eventCount }}</td>
            <td>{{ session.latestDriftScore !== null ? session.latestDriftScore : '—' }}</td>
            <td>
              <span :class="severityClass(session.latestSeverity)">
                {{ session.latestSeverity ?? '—' }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style scoped>
.badge {
  display: inline-block;
  padding: 0.15em 0.6em;
  border-radius: 0.25rem;
  font-size: 0.8em;
  font-weight: 600;
  text-transform: capitalize;
}
.badge--critical { background: #fee2e2; color: #991b1b; }
.badge--high     { background: #ffedd5; color: #9a3412; }
.badge--medium   { background: #dbeafe; color: #1e40af; }
.badge--low      { background: #dcfce7; color: #166534; }
.badge--none     { background: #f3f4f6; color: #6b7280; }
.empty-state     { color: #6b7280; margin-top: 1.5rem; }
</style>

<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import { useAdmin } from '~/composables/useAdmin'
import { useApi } from '~/composables/useApi'

const { t } = useLanguage()
const { list } = useEntity()
const { catalog } = useAdmin()
const { apiFetch } = useApi()

const counts = ref<Record<string, number>>({
  pending_review: 0,
  approved: 0,
  rejected: 0,
  failed: 0,
})
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + b, 0))
const loading = ref(true)
const error = ref(false)

const hidden = ref(false)
const ncSync = ref<{
  last_sync?: string
  created?: number
  skipped?: number
  failed?: number
  fetch_failed?: boolean
} | null>(null)

async function fetchCounts() {
  if (!catalog.some(e => e.id === 'ingest_log')) {
    hidden.value = true
    loading.value = false
    return
  }
  try {
    const result = await list('ingest_log', { page: { offset: 0, limit: 1000 } })
    const fresh: Record<string, number> = {
      pending_review: 0,
      approved: 0,
      rejected: 0,
      failed: 0,
    }
    for (const item of result.data) {
      const status = item.attributes.status as string
      if (status in fresh) {
        fresh[status]++
      }
    }
    counts.value = fresh
  } catch (e: any) {
    // Hide widget silently when entity type is not registered (404).
    const statusCode = e?.response?.status ?? e?.statusCode ?? 0
    if (statusCode === 404) {
      hidden.value = true
    } else {
      error.value = true
    }
  } finally {
    loading.value = false
  }
}

onMounted(fetchCounts)

async function fetchNcSyncStatus() {
  try {
    const result = await apiFetch<{ status: typeof ncSync.value }>('/api/admin/nc-sync-status')
    ncSync.value = result.status ?? null
  } catch {
    ncSync.value = null
  }
}

onMounted(fetchNcSyncStatus)
</script>

<template>
  <div v-if="!hidden" class="ingest-widget">
    <h2 class="ingest-widget-title">{{ t('ingest_widget_title') }}</h2>

    <div v-if="loading" class="ingest-widget-loading">{{ t('loading') }}</div>
    <div v-else-if="error" class="ingest-widget-error">{{ t('error_generic') }}</div>

    <template v-else>
      <div v-if="total === 0" class="ingest-widget-empty">
        {{ t('ingest_widget_empty') }}
      </div>
      <div v-else class="ingest-widget-counters">
        <NuxtLink
          v-for="status in ['pending_review', 'approved', 'rejected', 'failed']"
          :key="status"
          :to="`/ingest_log?filter[status]=${status}`"
          class="ingest-counter"
          :class="`ingest-counter--${status}`"
        >
          <span class="ingest-counter-value">{{ counts[status] }}</span>
          <span class="ingest-counter-label">{{ t(`ingest_status_${status}`) }}</span>
        </NuxtLink>
      </div>
      <div v-if="ncSync" class="nc-sync-panel">
        <div class="nc-sync-header">{{ t('nc_sync_widget_title') }}</div>
        <div class="nc-sync-metrics">
          <span>{{ t('nc_sync_last_sync') }}: {{ ncSync.last_sync || t('na') }}</span>
          <span>{{ t('nc_sync_created') }}: {{ ncSync.created ?? 0 }}</span>
          <span>{{ t('nc_sync_skipped') }}: {{ ncSync.skipped ?? 0 }}</span>
          <span>{{ t('nc_sync_failed') }}: {{ ncSync.failed ?? 0 }}</span>
        </div>
        <div class="nc-sync-links">
          <NuxtLink to="/admin/ingestion">{{ t('nc_sync_open_dashboard') }}</NuxtLink>
          <NuxtLink to="/teaching">{{ t('nc_sync_view_teachings') }}</NuxtLink>
          <NuxtLink to="/event">{{ t('nc_sync_view_events') }}</NuxtLink>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ingest-widget {
  margin-bottom: 24px;
  padding: 20px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}
.ingest-widget-title {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 12px;
}
.ingest-widget-empty {
  font-size: 14px;
  color: var(--color-muted);
}
.ingest-widget-counters {
  display: flex;
  gap: 12px;
}
.ingest-counter {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 12px;
  border-radius: 6px;
  text-decoration: none;
  color: var(--color-text);
  background: var(--color-bg);
  transition: border-color 0.15s;
  border: 1px solid transparent;
}
.ingest-counter:hover { border-color: var(--color-primary); }
.ingest-counter-value {
  font-size: 24px;
  font-weight: 700;
}
.ingest-counter-label {
  font-size: 12px;
  color: var(--color-muted);
  margin-top: 4px;
}
.ingest-counter--failed .ingest-counter-value { color: var(--color-danger, #c00); }
.ingest-counter--pending_review .ingest-counter-value { color: var(--color-warning, #b86e00); }
.ingest-counter--approved .ingest-counter-value { color: var(--color-success, #080); }
.ingest-widget-loading,
.ingest-widget-error {
  font-size: 13px;
  color: var(--color-muted);
}
.nc-sync-panel {
  margin-top: 12px;
  border-top: 1px solid var(--color-border);
  padding-top: 12px;
}
.nc-sync-header {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 6px;
}
.nc-sync-metrics {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  font-size: 12px;
  color: var(--color-muted);
}
.nc-sync-links {
  display: flex;
  gap: 12px;
  margin-top: 8px;
  font-size: 13px;
}
</style>

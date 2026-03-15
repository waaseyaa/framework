<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'

const { t } = useLanguage()
const { list } = useEntity()

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

async function fetchCounts() {
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
</style>

<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useWorkflowTransitions } from '~/composables/useWorkflowTransitions'

const props = defineProps<{
  entityType: string
  entityId: string
}>()

const { t } = useLanguage()
const { history, fetchTransitions } = useWorkflowTransitions()

const loaded = ref(false)
const fetchError = ref<string | null>(null)

async function loadAudit() {
  try {
    await fetchTransitions(props.entityType, props.entityId)
  } catch (e: unknown) {
    // Sidecar widget — surface the failure without breaking the entity page.
    const err = e as { message?: string }
    fetchError.value = err?.message ?? 'Failed to load transition history.'
  } finally {
    loaded.value = true
  }
}

onMounted(() => loadAudit())

function formatTimestamp(at: string): string {
  const parsed = new Date(at)
  if (Number.isNaN(parsed.getTime())) return at
  return parsed.toLocaleString()
}

// Reverse-chronological order — most recent transition at top.
const ordered = computed(() => history.value)
</script>

<template>
  <section v-if="loaded && (history.length > 0 || fetchError)" class="transition-history" data-testid="transition-history">
    <h2 class="transition-history-title">{{ t('workflow_history_title') }}</h2>

    <p v-if="fetchError" class="error">{{ fetchError }}</p>

    <p v-else-if="history.length === 0" class="empty-state">{{ t('workflow_history_empty') }}</p>

    <ol v-else class="timeline">
      <li v-for="(entry, idx) in ordered" :key="`${entry.at}-${idx}`" class="timeline-entry">
        <div class="timeline-marker" aria-hidden="true" />
        <div class="timeline-body">
          <div class="timeline-headline">
            <code class="transition-chip">{{ entry.transition }}</code>
            <span class="state-from">{{ entry.from }}</span>
            <span class="state-arrow" aria-hidden="true">→</span>
            <span class="state-to">{{ entry.to }}</span>
          </div>
          <div class="timeline-meta">
            {{ t('workflow_history_by') }} <code>uid:{{ entry.uid }}</code>
            <span class="timeline-meta-sep">·</span>
            {{ t('workflow_history_at') }}
            <time :datetime="entry.at">{{ formatTimestamp(entry.at) }}</time>
          </div>
        </div>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.transition-history {
  margin-top: 32px;
  padding-top: 24px;
  border-top: 1px solid var(--color-border);
}

.transition-history-title {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 16px;
  color: var(--color-muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.timeline {
  list-style: none;
  margin: 0;
  padding: 0;
  position: relative;
}

.timeline::before {
  content: '';
  position: absolute;
  top: 6px;
  bottom: 6px;
  left: 6px;
  width: 2px;
  background: var(--color-border);
}

.timeline-entry {
  position: relative;
  padding-left: 28px;
  margin-bottom: 16px;
}

.timeline-marker {
  position: absolute;
  top: 4px;
  left: 0;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--color-primary);
  border: 2px solid var(--color-surface);
  box-shadow: 0 0 0 1px var(--color-primary);
}

.timeline-body {
  font-size: 13px;
}

.timeline-headline {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}

.transition-chip {
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 4px;
  background: rgba(15, 118, 110, 0.12);
  color: var(--color-primary-hover);
}

.state-from,
.state-to {
  font-weight: 500;
}

.state-arrow {
  color: var(--color-muted);
}

.timeline-meta {
  color: var(--color-muted);
  font-size: 12px;
}

.timeline-meta-sep {
  margin: 0 6px;
}
</style>

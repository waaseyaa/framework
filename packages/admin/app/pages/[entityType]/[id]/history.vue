<script setup lang="ts">
// Per-record history (#2419).
//
// A record's own addressable history surface. The record editor answers "what
// does this record say now" and the pipeline answers for the whole entity type;
// neither answers "what happened to THIS record", which is why the History
// action had no destination to point at.
//
// The surface is permission-checked server-side by the record's own view
// access. A principal who may not view the record receives a refusal, and this
// page renders no history — never an empty timeline, which would itself
// disclose that the record exists and has never been touched.
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'
import { useEntity } from '~/composables/useEntity'

interface RevisionEntry {
  revisionId: number | string | null
  createdAt: string | null
  author: number | null
  log: string | null
  isCurrent: boolean
  isLatest: boolean
}

const route = useRoute()
const { t, entityLabel: translateEntityLabel } = useLanguage()
const { runAction } = useEntity()

const entityType = computed(() => route.params.entityType as string)
const entityId = computed(() => route.params.id as string)
const { schema, fetch: fetchSchema } = useSchema(entityType.value)

const revisions = ref<RevisionEntry[]>([])
const loading = ref(true)
// `available` is false whenever the server declined to expose a history
// surface — unauthorized, missing, or an entity type that keeps no revisions.
// The page then shows a single not-available message rather than an empty list.
const available = ref(false)
const loadError = ref<string | null>(null)

const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('history_title')} | ${entityLabel.value} | ${appName}`) })

function isRevisionEntry(value: unknown): value is RevisionEntry {
  return typeof value === 'object' && value !== null && 'revisionId' in value
}

onMounted(async () => {
  void fetchSchema({ id: entityId.value })
  try {
    const result = await runAction(entityType.value, 'history', { id: entityId.value }) as
      { revisions?: unknown } | null
    const entries = Array.isArray(result?.revisions) ? result.revisions : []
    revisions.value = entries.filter(isRevisionEntry)
    available.value = true
  } catch (e: unknown) {
    const err = e as { data?: { error?: { detail?: string } }; message?: string }
    available.value = false
    loadError.value = err?.data?.error?.detail ?? err?.message ?? null
  } finally {
    loading.value = false
  }
})

function formatTimestamp(at: string | null): string {
  if (!at) return t('history_unknown_time')
  const parsed = new Date(at)
  return Number.isNaN(parsed.getTime()) ? at : parsed.toLocaleString()
}

// A null author is an unattributed revision, not the anonymous account. The two
// are distinct in storage and must stay distinct here.
function formatAuthor(author: number | null): string {
  return author === null ? t('history_unattributed') : `uid:${author}`
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('history_for', { type: entityLabel }) }}</h1>
      <div class="page-actions">
        <NuxtLink :to="`/${entityType}/${entityId}`" class="btn">
          {{ t('history_back_to_record') }}
        </NuxtLink>
      </div>
    </div>

    <p v-if="loading" class="loading">{{ t('loading') }}</p>

    <p v-else-if="!available" class="error" role="alert" data-testid="history-unavailable">
      {{ loadError ?? t('history_unavailable') }}
    </p>

    <p v-else-if="revisions.length === 0" class="empty-state" data-testid="history-empty">
      {{ t('history_empty') }}
    </p>

    <ol v-else class="timeline" data-testid="history-timeline">
      <li
        v-for="entry in revisions"
        :key="String(entry.revisionId)"
        class="timeline-entry"
        :data-anchor="`history:${entityType}:${entityId}:${entry.revisionId}`"
      >
        <div class="timeline-marker" aria-hidden="true" />
        <div class="timeline-body">
          <div class="timeline-headline">
            <code class="revision-chip">#{{ entry.revisionId }}</code>
            <span v-if="entry.isCurrent" class="status-pill">{{ t('history_current') }}</span>
            <span v-if="entry.isLatest && !entry.isCurrent" class="status-pill">{{ t('history_latest') }}</span>
          </div>
          <div class="timeline-meta">
            {{ t('history_by') }} <code>{{ formatAuthor(entry.author) }}</code>
            <span class="timeline-meta-sep">·</span>
            <time :datetime="entry.createdAt ?? undefined">{{ formatTimestamp(entry.createdAt) }}</time>
          </div>
          <p v-if="entry.log" class="timeline-log">{{ entry.log }}</p>
        </div>
      </li>
    </ol>
  </div>
</template>

<style scoped>
.page-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.timeline {
  list-style: none;
  margin: 16px 0 0;
  padding: 0;
}

.timeline-entry {
  display: flex;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid var(--color-border, #e3e3e3);
}

.timeline-marker {
  width: 10px;
  height: 10px;
  margin-top: 6px;
  border-radius: 50%;
  flex: 0 0 auto;
  background: var(--color-primary, #0f766e);
}

.timeline-headline {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.timeline-meta {
  margin-top: 4px;
  font-size: 13px;
  color: var(--color-text-muted, #666);
}

.timeline-meta-sep {
  margin: 0 6px;
}

.timeline-log {
  margin: 6px 0 0;
}

.status-pill {
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  background: var(--color-primary-subtle, rgba(15, 118, 110, 0.12));
  color: var(--color-primary, #0f766e);
}
</style>

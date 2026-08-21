<script setup lang="ts">
import type { EntityResource } from '~/contracts/transport'
import { useAdmin } from '~/composables/useAdmin'
import { useEntity } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'

interface RevisionEntry {
  revisionId: number | string | null
  createdAt: string | null
  author: number | null
  log: string | null
  isCurrent: boolean
  isLatest: boolean
}

const props = defineProps<{ entityType: string; entityId: string; compact?: boolean }>()
const { t } = useLanguage()
const { hasCapability } = useAdmin()
const { get, runAction } = useEntity()
const revisions = ref<RevisionEntry[]>([])
const current = ref<EntityResource | null>(null)
const selected = ref<{ revisionId: number; entity: EntityResource } | null>(null)
const loading = ref(true)
const available = ref(false)
const busy = ref(false)
const error = ref('')
const restored = ref('')
const confirmRestore = ref(false)
const previewDialog = ref<HTMLDialogElement | null>(null)
const previewUrl = ref('')
const previewSize = ref<'desktop' | 'mobile'>('desktop')

const canRestore = computed(() => hasCapability(props.entityType, 'update'))
const latestRevisionId = computed(() => {
  const id = revisions.value.find(entry => entry.isLatest)?.revisionId
  return typeof id === 'number' && id > 0 ? id : null
})
const comparedFields = computed(() => {
  const before = selected.value?.entity.attributes ?? {}
  const now = current.value?.attributes ?? {}
  return [...new Set([...Object.keys(before), ...Object.keys(now)])].sort().map(name => ({
    name,
    before: display(before[name]),
    now: display(now[name]),
    changed: JSON.stringify(before[name]) !== JSON.stringify(now[name]),
  }))
})

function detail(value: unknown): string {
  const candidate = value as { detail?: string; data?: { error?: { detail?: string } }; message?: string }
  return candidate?.detail ?? candidate?.data?.error?.detail ?? candidate?.message ?? t('history_unavailable')
}

function display(value: unknown): string {
  if (value === undefined) return '—'
  if (typeof value === 'string') return value
  return JSON.stringify(value, null, 2)
}

function isRevisionEntry(value: unknown): value is RevisionEntry {
  return typeof value === 'object' && value !== null && 'revisionId' in value
}

async function load() {
  loading.value = true
  error.value = ''
  selected.value = null
  try {
    const [entity, history] = await Promise.all([
      get(props.entityType, props.entityId),
      runAction(props.entityType, 'history', { id: props.entityId }) as Promise<{ revisions?: unknown }>,
    ])
    current.value = entity
    revisions.value = Array.isArray(history?.revisions) ? history.revisions.filter(isRevisionEntry) : []
    available.value = true
  } catch (cause) {
    available.value = false
    error.value = detail(cause)
  } finally {
    loading.value = false
  }
}

async function compare(entry: RevisionEntry) {
  if (typeof entry.revisionId !== 'number' || busy.value) return
  busy.value = true
  error.value = ''
  try {
    selected.value = await runAction(props.entityType, 'revision', {
      id: props.entityId,
      revision_id: entry.revisionId,
    }) as { revisionId: number; entity: EntityResource }
  } catch (cause) {
    error.value = detail(cause)
  } finally {
    busy.value = false
  }
}

async function restore() {
  if (!selected.value || latestRevisionId.value === null || busy.value) return
  busy.value = true
  confirmRestore.value = false
  error.value = ''
  try {
    await runAction(props.entityType, 'restore-revision', {
      id: props.entityId,
      revision_id: selected.value.revisionId,
      expected_latest_revision_id: latestRevisionId.value,
    })
    restored.value = t('history_restored', { revision: String(selected.value.revisionId) })
    await load()
  } catch (cause) {
    error.value = detail(cause)
  } finally {
    busy.value = false
  }
}

async function preview() {
  if (!selected.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    const grant = await runAction(props.entityType, 'revision-preview', {
      id: props.entityId,
      revision_id: selected.value.revisionId,
    }) as { revisionId?: unknown; previewUrl?: unknown }
    if (grant.revisionId !== selected.value.revisionId || typeof grant.previewUrl !== 'string'
      || grant.previewUrl.startsWith('//')
      || (!grant.previewUrl.startsWith('/') && !grant.previewUrl.startsWith('https://'))) {
      throw new Error(t('preview_invalid_response'))
    }
    previewUrl.value = grant.previewUrl
    await nextTick()
    previewDialog.value?.showModal()
  } catch (cause) {
    error.value = detail(cause)
  } finally {
    busy.value = false
  }
}

function closePreview() {
  previewDialog.value?.close()
  previewUrl.value = ''
}

function formatTimestamp(at: string | null): string {
  if (!at) return t('history_unknown_time')
  const parsed = new Date(at)
  return Number.isNaN(parsed.getTime()) ? at : parsed.toLocaleString()
}

onMounted(load)
</script>

<template>
  <section class="revision-recovery" :class="{ compact }" :aria-busy="loading || busy ? 'true' : 'false'">
    <h2>{{ t('history_title') }}</h2>
    <p v-if="loading" class="loading">{{ t('loading') }}</p>
    <p v-else-if="!available" class="error" role="alert" data-testid="history-unavailable">{{ error || t('history_unavailable') }}</p>
    <p v-else-if="revisions.length === 0" class="empty-state" data-testid="history-empty">{{ t('history_empty') }}</p>
    <template v-else>
      <p v-if="error" class="error" role="alert">{{ error }}</p>
      <p v-if="restored" class="success" role="status">{{ restored }}</p>
      <ol class="timeline" data-testid="history-timeline">
        <li v-for="entry in revisions" :key="String(entry.revisionId)" class="timeline-entry">
          <div>
            <code>#{{ entry.revisionId }}</code>
            <span v-if="entry.isCurrent" class="status-pill">{{ t('history_current') }}</span>
            <span v-if="entry.isLatest && !entry.isCurrent" class="status-pill">{{ t('history_latest') }}</span>
            <small>{{ t('history_by') }} {{ entry.author === null ? t('history_unattributed') : `uid:${entry.author}` }} · {{ formatTimestamp(entry.createdAt) }}</small>
            <p v-if="entry.log">{{ entry.log }}</p>
          </div>
          <button type="button" class="btn" :disabled="busy || typeof entry.revisionId !== 'number'" @click="compare(entry)">{{ t('history_compare') }}</button>
        </li>
      </ol>
    </template>

    <section v-if="selected" class="comparison" data-testid="revision-comparison">
      <header>
        <h3>{{ t('history_comparing', { revision: String(selected.revisionId) }) }}</h3>
        <div class="comparison-actions">
          <button type="button" class="btn" :disabled="busy" @click="preview">{{ t('history_preview') }}</button>
          <button v-if="canRestore" type="button" class="btn btn-primary" :disabled="busy" @click="confirmRestore = true">{{ t('history_restore') }}</button>
        </div>
      </header>
      <div class="comparison-table" role="region" :aria-label="t('history_comparison')" tabindex="0">
        <table><thead><tr><th>{{ t('field') }}</th><th>{{ t('history_selected') }}</th><th>{{ t('history_working_copy') }}</th></tr></thead>
          <tbody><tr v-for="field in comparedFields" :key="field.name" :class="{ changed: field.changed }"><th>{{ field.name }}</th><td><pre>{{ field.before }}</pre></td><td><pre>{{ field.now }}</pre></td></tr></tbody>
        </table>
      </div>
    </section>

    <CommonConfirmDialog :open="confirmRestore" :message="t('history_restore_confirm', { revision: String(selected?.revisionId ?? '') })" :confirm-label="t('history_restore')" @cancel="confirmRestore = false" @confirm="restore" />
    <dialog ref="previewDialog" class="revision-preview" @cancel.prevent="closePreview">
      <header><h3>{{ t('history_preview') }}</h3><div><button class="btn" type="button" @click="previewSize = 'desktop'">{{ t('preview_desktop') }}</button><button class="btn" type="button" @click="previewSize = 'mobile'">{{ t('preview_mobile') }}</button><button class="btn" type="button" @click="closePreview">×</button></div></header>
      <iframe v-if="previewUrl" :src="previewUrl" :title="t('preview_frame_title')" :class="previewSize" />
    </dialog>
  </section>
</template>

<style scoped>
.revision-recovery { margin-top: 1.5rem; }
.timeline { list-style: none; padding: 0; }
.timeline-entry, .comparison header, .revision-preview header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.timeline-entry { padding: .75rem 0; border-bottom: 1px solid var(--color-border, #ddd); }
.timeline-entry small { display: block; margin-top: .35rem; color: var(--color-text-muted, #666); }
.status-pill { margin-left: .5rem; padding: .15rem .5rem; border-radius: 999px; background: var(--color-primary-subtle, #def7ec); }
.comparison-actions { display: flex; gap: .5rem; }
.comparison-table { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: .6rem; border: 1px solid var(--color-border, #ddd); text-align: left; vertical-align: top; }
tr.changed { background: var(--color-warning-subtle, #fff8db); }
pre { margin: 0; white-space: pre-wrap; word-break: break-word; }
.revision-preview { width: min(1180px, calc(100vw - 2rem)); height: min(860px, calc(100vh - 2rem)); border: 0; border-radius: .75rem; }
.revision-preview iframe { display: block; height: calc(100% - 4rem); margin: 1rem auto; border: 1px solid var(--color-border, #ddd); }
.revision-preview iframe.desktop { width: 100%; }
.revision-preview iframe.mobile { width: min(390px, 100%); }
@media (max-width: 700px) { .timeline-entry, .comparison header { flex-direction: column; } }
</style>

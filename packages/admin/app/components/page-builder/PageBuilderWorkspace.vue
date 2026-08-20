<script setup lang="ts">
import type {
  PageBuilderBlock,
  PageBuilderBlockDefinition,
  PageBuilderCommand,
  PageBuilderLayoutDefinition,
  PageBuilderSection,
} from '~/contracts/pageBuilder'
import ConfirmDialog from '~/components/common/ConfirmDialog.vue'
import { usePageBuilder } from '~/composables/usePageBuilder'
import type { SchemaProperty } from '~/composables/useSchema'
import type { EmbedFailure } from '~/runtime/embedLifecycle'

const props = defineProps<{
  surface: string
  entityId: string
}>()

const emit = defineEmits<{
  ready: []
  dirty: [dirty: boolean]
  saved: []
  failure: [failure: EmbedFailure]
}>()

type PreviewSize = 'desktop' | 'tablet' | 'mobile'
type JsonSchemaProperty = Omit<SchemaProperty, 'enum'> & {
  title?: string
  enum?: Array<string | number | boolean>
}

const { t } = useLanguage()
const {
  definitions, draft, previewUrl, revisions, comparedRevision, loading, saving, error, failure, conflict,
  advisoryReview, advisoryUnsupported,
  load, apply, loadLatestForConflict, retryConflict, dismissConflict,
  confirmAdvisoryReview, declineAdvisoryReview,
  refreshPreview, loadHistory, compareRevision, restoreRevision,
} = usePageBuilder(
  props.surface,
  props.entityId,
)
const selectedBlockId = ref<string | null>(null)
const selectedSectionId = ref<string | null>(null)
const blockDestination = ref('')
const sectionLayoutChoice = ref('')
const previewSize = ref<PreviewSize>('desktop')
const editableConfig = ref<Record<string, unknown>>({})
const announcement = ref('')
const configDirty = ref(false)
const historyOpen = ref(false)
const advisoryBanner = ref<HTMLElement | null>(null)
const confirmation = ref({ open: false, title: '', message: '', confirmLabel: '', dangerous: false })
let resolveConfirmation: ((confirmed: boolean) => void) | null = null
let confirmationReturnFocus: HTMLElement | null = null
let autosaveTimer: ReturnType<typeof setTimeout> | null = null

watch(configDirty, dirty => emit('dirty', dirty), { flush: 'sync' })
watch(failure, value => { if (value) emit('failure', value) })
watch(advisoryReview, async (review) => {
  if (!review) return
  await nextTick()
  advisoryBanner.value?.focus()
})

const commonLayoutPrefix = computed(() => {
  const layouts = definitions.value?.layouts ?? []
  if (layouts.length < 2) return ''
  const prefix = layouts[0]?.id.split(/[_-]/, 1)[0] ?? ''
  return prefix && layouts.every(layout => layout.id.startsWith(`${prefix}_`) || layout.id.startsWith(`${prefix}-`))
    ? prefix
    : ''
})

function layoutLabel(id: string): string {
  const prefix = commonLayoutPrefix.value
  const readable = prefix ? id.replace(new RegExp(`^${prefix}[_-]`), '') : id
  const words = readable.replaceAll('_', ' ').replaceAll('-', ' ')
  return words.charAt(0).toUpperCase() + words.slice(1)
}

function cloneConfig(config: Record<string, unknown>): Record<string, unknown> {
  return JSON.parse(JSON.stringify(config)) as Record<string, unknown>
}

function requestConfirmation(options: { title: string, message: string, confirmLabel: string, dangerous?: boolean }): Promise<boolean> {
  if (resolveConfirmation) return Promise.resolve(false)
  confirmationReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
  confirmation.value = { open: true, dangerous: options.dangerous ?? false, ...options }
  return new Promise((resolve) => { resolveConfirmation = resolve })
}

async function decideConfirmation(confirmed: boolean) {
  confirmation.value.open = false
  const resolve = resolveConfirmation
  resolveConfirmation = null
  resolve?.(confirmed)
  if (!confirmed) {
    await nextTick()
    confirmationReturnFocus?.focus()
  }
  confirmationReturnFocus = null
}

const blocks = computed(() => {
  const result: Array<{ block: PageBuilderBlock, section: PageBuilderSection, regionId: string, position: number }> = []
  for (const section of draft.value?.document.sections ?? []) {
    for (const [regionId, regionBlocks] of Object.entries(section.regions)) {
      regionBlocks.forEach((block, position) => result.push({ block, section, regionId, position }))
    }
  }
  return result
})
const selectedEntry = computed(() => blocks.value.find(entry => entry.block.id === selectedBlockId.value) ?? null)
const sections = computed(() => draft.value?.document.sections ?? [])
const selectedSection = computed(() => sections.value.find(section => section.id === selectedSectionId.value) ?? null)
const selectedSectionIndex = computed(() => sections.value.findIndex(section => section.id === selectedSectionId.value))
const editingBlocked = computed(() => saving.value || conflict.value !== null || advisoryReview.value !== null)
const sectionPosition = (section: PageBuilderSection) => sections.value.findIndex(candidate => candidate.id === section.id) + 1
const sectionBlockCount = (section: PageBuilderSection) => Object.values(section.regions).reduce((count, regionBlocks) => count + regionBlocks.length, 0)
const activeTemplate = computed(() => definitions.value?.templates.find(template => (
  template.id === draft.value?.document.template.id && template.version === draft.value?.document.template.version
)) ?? null)
const availableSectionLayouts = computed(() => (definitions.value?.layouts ?? []).filter(layout => (
  !activeTemplate.value || activeTemplate.value.allowed_layouts.includes(layout.id)
)))
const availableBlocks = computed(() => (definitions.value?.blocks ?? []).filter(block => (
  !activeTemplate.value || activeTemplate.value.allowed_blocks.includes(block.id)
)))
const blockDestinations = computed(() => {
  const entry = selectedEntry.value
  if (!entry || !definitions.value) return []
  return sections.value.flatMap(section => {
    const layout = definitions.value!.layouts.find(candidate => (
      candidate.id === section.layout.id && candidate.version === section.layout.version
    ))
    if (!layout?.allowed_blocks.includes(entry.block.type)) return []
    return Object.keys(section.regions).map(regionId => ({
      value: `${section.id}::${regionId}`,
      section,
      regionId,
      label: `${layoutLabel(section.layout.id)} · ${regionId}`,
    }))
  })
})
const selectedDefinition = computed(() => definitions.value?.blocks.find(
  definition => definition.id === selectedEntry.value?.block.type && definition.version === selectedEntry.value?.block.version,
) ?? null)
const configProperties = computed<Record<string, JsonSchemaProperty>>(() => {
  const properties = selectedDefinition.value?.config_schema?.properties
  return properties && typeof properties === 'object' && !Array.isArray(properties)
    ? properties as Record<string, JsonSchemaProperty>
    : {}
})
const previewWidth = computed(() => ({ desktop: '100%', tablet: '768px', mobile: '390px' })[previewSize.value])
const comparisonChanges = computed(() => {
  if (!draft.value || !comparedRevision.value) return []
  const flatten = (document: typeof draft.value.document) => {
    const result: Record<string, { block: PageBuilderBlock, location: string }> = {}
    document.sections.forEach((section, sectionIndex) => Object.entries(section.regions).forEach(([regionId, regionBlocks]) => regionBlocks.forEach((block, position) => {
      result[block.id] = { block, location: `${sectionIndex}:${section.layout.id}:${regionId}:${position}` }
    })))
    return result
  }
  const current = flatten(draft.value.document)
  const prior = flatten(comparedRevision.value.document)
  const ids = [...new Set([...Object.keys(current), ...Object.keys(prior)])]
  const changes = ids.flatMap((id) => {
    if (!prior[id]) return [{ id, kind: 'added', label: current[id]!.block.type }]
    if (!current[id]) return [{ id, kind: 'removed', label: prior[id]!.block.type }]
    if (JSON.stringify(current[id]) !== JSON.stringify(prior[id])) return [{ id, kind: 'changed', label: current[id]!.block.type }]
    return []
  })
  const structure = (document: typeof draft.value.document) => document.sections.map(section => ({
    id: section.id,
    layout: section.layout,
    regions: Object.keys(section.regions),
  }))
  if (JSON.stringify(structure(draft.value.document)) !== JSON.stringify(structure(comparedRevision.value.document))) {
    changes.unshift({ id: 'page-structure', kind: 'changed', label: t('page_builder_page_structure') })
  }
  return changes
})

watch(selectedEntry, (entry) => {
  editableConfig.value = entry ? cloneConfig(entry.block.config) : {}
  blockDestination.value = entry ? `${entry.section.id}::${entry.regionId}` : ''
}, { immediate: true })

watch(selectedSection, (section) => {
  sectionLayoutChoice.value = section ? `${section.layout.id}::${section.layout.version}` : ''
}, { immediate: true })

function secureId(prefix: 'blk' | 'sec'): string {
  if (typeof crypto === 'undefined' || typeof crypto.randomUUID !== 'function') {
    throw new Error(t('page_builder_secure_id_unavailable'))
  }
  return `${prefix}_${crypto.randomUUID().replaceAll('-', '')}`
}

async function selectBlock(blockId: string) {
  if (configDirty.value && !await saveSelectedBlock(true)) return
  selectedBlockId.value = blockId
  selectedSectionId.value = blocks.value.find(entry => entry.block.id === blockId)?.section.id ?? null
}

async function selectSection(sectionId: string) {
  if (configDirty.value && !await saveSelectedBlock(true)) return
  if (!sections.value.some(section => section.id === sectionId)) return
  selectedSectionId.value = sectionId
  selectedBlockId.value = null
}

function fieldValue(key: string): string | number | boolean {
  const value = editableConfig.value[key]
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ? value : ''
}

function selectValue(key: string): string | number {
  const value = fieldValue(key)
  return typeof value === 'boolean' ? String(value) : value
}

function setFieldValue(key: string, schema: JsonSchemaProperty, event: Event) {
  const input = event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
  if (schema.type === 'boolean' && input instanceof HTMLInputElement) editableConfig.value[key] = input.checked
  else if (schema.type === 'number' || schema.type === 'integer') editableConfig.value[key] = Number(input.value)
  else editableConfig.value[key] = input.value
  scheduleAutosave()
}

function setConfigValue(key: string, value: unknown) {
  editableConfig.value[key] = value
  scheduleAutosave()
}

function scheduleAutosave() {
  configDirty.value = true
  if (autosaveTimer) clearTimeout(autosaveTimer)
  autosaveTimer = setTimeout(() => void saveSelectedBlock(true), 1500)
}

function configFieldId(key: string): string {
  const token = [...key].map(character => character.codePointAt(0)!.toString(16)).join('-')
  return `page-builder-config-${token}`
}

function configFieldRequired(key: string): boolean {
  const required = selectedDefinition.value?.config_schema?.required
  return Array.isArray(required) && required.includes(key)
}

function entityReferenceSchema(schema: JsonSchemaProperty): SchemaProperty {
  return schema as SchemaProperty
}

async function run(command: PageBuilderCommand, message: string): Promise<boolean> {
  const saved = await apply(command)
  if (!saved) return false
  announcement.value = ''
  await nextTick()
  announcement.value = message
  await refreshPreview()
  configDirty.value = false
  emit('saved')
  return true
}

async function loadConflictComparison() {
  if (await loadLatestForConflict()) {
    historyOpen.value = true
    await refreshPreview()
  }
}

async function reapplyConflict() {
  if (await retryConflict()) {
    announcement.value = ''
    await nextTick()
    announcement.value = t('page_builder_conflict_reapplied')
    configDirty.value = false
    historyOpen.value = false
    await loadHistory()
    await refreshPreview()
    emit('saved')
  }
}

function keepLatestAfterConflict() {
  dismissConflict()
  configDirty.value = false
}

async function acknowledgeAdvisory() {
  if (await confirmAdvisoryReview()) {
    announcement.value = ''
    await nextTick()
    announcement.value = t('page_builder_advisory_acknowledged')
    configDirty.value = false
    await loadHistory()
    await refreshPreview()
    emit('saved')
  }
}

/**
 * Declining writes nothing and changes no editor state: the pending edit stays
 * exactly where the author left it, still dirty and still unsaved.
 */
function declineAdvisory() {
  declineAdvisoryReview()
}

async function focusOutlineTarget(kind: 'block' | 'section', id: string | null) {
  if (!id) return
  await nextTick()
  const attribute = kind === 'block' ? 'data-block-select' : 'data-section-select'
  const target = [...document.querySelectorAll<HTMLElement>(`[${attribute}]`)].find(element => (
    element.getAttribute(attribute) === id
  ))
  target?.focus()
}

async function saveSelectedBlock(automatic = false): Promise<boolean> {
  if (!selectedEntry.value || (!configDirty.value && automatic)) return true
  if (autosaveTimer) clearTimeout(autosaveTimer)
  autosaveTimer = null
  const saved = await run({
    type: 'configure_block',
    block_id: selectedEntry.value.block.id,
    config: cloneConfig(editableConfig.value),
  }, automatic ? t('page_builder_autosaved') : t('page_builder_change_saved'))
  if (saved) {
    await loadHistory()
  }
  return saved
}

async function toggleHistory() {
  historyOpen.value = !historyOpen.value
  if (historyOpen.value) await loadHistory()
}

async function restoreComparedRevision() {
  const revisionId = comparedRevision.value?.entity_revision_id
  if (!revisionId) return
  if (configDirty.value && !await saveSelectedBlock(true)) return
  if (!await requestConfirmation({
    title: t('page_builder_restore_title'),
    message: t('page_builder_restore_confirm', { revision: String(revisionId) }),
    confirmLabel: t('page_builder_restore_as_draft'),
  })) return
  if (await restoreRevision(revisionId)) {
    announcement.value = ''
    await nextTick()
    announcement.value = t('page_builder_revision_restored', { revision: String(revisionId) })
    await refreshPreview()
    emit('saved')
  }
}

async function addBlock(definition: PageBuilderBlockDefinition) {
  if (configDirty.value && !await saveSelectedBlock(true)) return
  const selected = selectedEntry.value
  const section = selected?.section ?? draft.value?.document.sections[0]
  if (!section) return
  const regionId = selected?.regionId ?? Object.keys(section.regions)[0]
  if (!regionId) return
  const blockId = secureId('blk')
  const config: Record<string, unknown> = {}
  const properties = definition.config_schema.properties
  if (properties && typeof properties === 'object' && !Array.isArray(properties)) {
    for (const [key, schema] of Object.entries(properties as Record<string, JsonSchemaProperty>)) {
      if (schema.default !== undefined) config[key] = schema.default
    }
  }
  const saved = await run({
    type: 'add_block',
    section_id: section.id,
    region_id: regionId,
    position: selected ? selected.position + 1 : section.regions[regionId]?.length ?? 0,
    block: { id: blockId, type: definition.id, version: definition.version, config },
  }, t('page_builder_block_added', { block: definition.label }))
  if (saved) {
    selectedBlockId.value = blockId
    await focusOutlineTarget('block', blockId)
  }
}

async function addSection(layout: PageBuilderLayoutDefinition) {
  if (!draft.value) return
  if (configDirty.value && !await saveSelectedBlock(true)) return
  const regions = Object.fromEntries(layout.regions.map(region => [region, []]))
  const sectionId = secureId('sec')
  if (await run({
    type: 'add_section',
    position: draft.value.document.sections.length,
    section: { id: sectionId, layout: { id: layout.id, version: layout.version }, regions },
  }, t('page_builder_section_added'))) {
    selectedSectionId.value = sectionId
    selectedBlockId.value = null
    await focusOutlineTarget('section', sectionId)
  }
}

async function removeSelectedBlock() {
  if (!selectedEntry.value) return
  if (configDirty.value && !await saveSelectedBlock(true)) return
  const blockId = selectedEntry.value.block.id
  if (!await requestConfirmation({
    title: t('page_builder_remove_block'),
    message: t('page_builder_remove_block_confirm'),
    confirmLabel: t('page_builder_remove_block'),
    dangerous: true,
  })) return
  if (await run({ type: 'remove_block', block_id: blockId }, t('page_builder_block_removed'))) {
    selectedBlockId.value = null
    await focusOutlineTarget('section', selectedSectionId.value)
  }
}

async function moveSelectedBlock(offset: -1 | 1) {
  if (configDirty.value && !await saveSelectedBlock(true)) return
  const entry = selectedEntry.value
  /* v8 ignore next -- the controls only render while selectedEntry exists */
  if (!entry) return
  const destination = entry.position + offset
  const regionBlocks = entry.section.regions[entry.regionId] ?? []
  if (destination < 0 || destination >= regionBlocks.length) return
  if (await run({
    type: 'move_block',
    block_id: entry.block.id,
    destination_section_id: entry.section.id,
    destination_region_id: entry.regionId,
    position: destination,
  }, t('page_builder_block_moved'))) await focusOutlineTarget('block', entry.block.id)
}

async function moveSelectedBlockToRegion() {
  const requestedDestination = blockDestination.value
  if (configDirty.value && !await saveSelectedBlock(true)) return
  const entry = selectedEntry.value
  const destination = blockDestinations.value.find(candidate => candidate.value === requestedDestination)
  if (!entry || !destination || destination.value === `${entry.section.id}::${entry.regionId}`) return
  const position = destination.section.regions[destination.regionId]?.length ?? 0
  if (await run({
    type: 'move_block',
    block_id: entry.block.id,
    destination_section_id: destination.section.id,
    destination_region_id: destination.regionId,
    position,
  }, t('page_builder_block_moved_to_region'))) {
    selectedSectionId.value = destination.section.id
    await focusOutlineTarget('block', entry.block.id)
  }
}

async function duplicateSelectedBlock() {
  if (configDirty.value && !await saveSelectedBlock(true)) return
  const entry = selectedEntry.value
  if (!entry) return
  const duplicateId = secureId('blk')
  if (await run({
    type: 'duplicate_block',
    source_block_id: entry.block.id,
    duplicate_block_id: duplicateId,
  }, t('page_builder_block_duplicated'))) {
    selectedBlockId.value = duplicateId
    await focusOutlineTarget('block', duplicateId)
  }
}

async function moveSelectedSection(offset: -1 | 1) {
  const section = selectedSection.value
  const index = selectedSectionIndex.value
  if (!section || index < 0) return
  const position = index + offset
  if (position < 0 || position >= sections.value.length) return
  if (await run({ type: 'move_section', section_id: section.id, position }, t('page_builder_section_moved'))) {
    await focusOutlineTarget('section', section.id)
  }
}

async function changeSelectedSectionLayout() {
  const section = selectedSection.value
  const layout = availableSectionLayouts.value.find(candidate => (
    `${candidate.id}::${candidate.version}` === sectionLayoutChoice.value
  ))
  if (!section || !layout || (layout.id === section.layout.id && layout.version === section.layout.version)) return
  if (await run({
    type: 'change_section_layout',
    section_id: section.id,
    layout_id: layout.id,
    layout_version: layout.version,
  }, t('page_builder_section_layout_changed'))) await focusOutlineTarget('section', section.id)
}

async function duplicateSelectedSection() {
  const source = selectedSection.value
  if (!source) return
  const duplicateSectionId = secureId('sec')
  const duplicateBlockIds = Object.fromEntries(Object.values(source.regions).flat().map(block => [
    block.id,
    secureId('blk'),
  ]))
  if (await run({
    type: 'duplicate_section',
    source_section_id: source.id,
    duplicate_section_id: duplicateSectionId,
    duplicate_block_ids: duplicateBlockIds,
  }, t('page_builder_section_duplicated'))) {
    selectedSectionId.value = duplicateSectionId
    selectedBlockId.value = null
    await focusOutlineTarget('section', duplicateSectionId)
  }
}

async function removeSelectedSection() {
  const section = selectedSection.value
  const index = selectedSectionIndex.value
  if (!section || sections.value.length <= 1) return
  const blockCount = Object.values(section.regions).reduce((count, blocks) => count + blocks.length, 0)
  if (!await requestConfirmation({
    title: t('page_builder_remove_section'),
    message: t('page_builder_remove_section_confirm', { count: String(blockCount) }),
    confirmLabel: t('page_builder_remove_section'),
    dangerous: true,
  })) return
  const fallbackSectionId = sections.value[index + 1]?.id ?? sections.value[index - 1]?.id ?? null
  if (await run({ type: 'remove_section', section_id: section.id }, t('page_builder_section_removed'))) {
    selectedSectionId.value = fallbackSectionId
    selectedBlockId.value = null
    await focusOutlineTarget('section', fallbackSectionId)
  }
}

function onPreviewMessage(event: MessageEvent) {
  if (event.origin !== window.location.origin || event.source !== (document.querySelector('[data-page-builder-preview]') as HTMLIFrameElement | null)?.contentWindow) return
  const payload = event.data
  if (!payload || payload.type !== 'waaseyaa.page-builder.select' || typeof payload.blockId !== 'string') return
  if (blocks.value.some(entry => entry.block.id === payload.blockId)) selectBlock(payload.blockId)
}

onMounted(async () => {
  window.addEventListener('message', onPreviewMessage)
  await load()
  if (draft.value && definitions.value) emit('ready')
  await loadHistory()
  if (blocks.value[0]) {
    selectedBlockId.value = blocks.value[0].block.id
    selectedSectionId.value = blocks.value[0].section.id
  } else if (sections.value[0]) selectedSectionId.value = sections.value[0].id
  await refreshPreview()
})
onBeforeUnmount(() => {
  window.removeEventListener('message', onPreviewMessage)
  if (autosaveTimer) clearTimeout(autosaveTimer)
  decideConfirmation(false)
})
</script>

<template>
  <section class="page-builder" :aria-busy="loading || saving">
    <ConfirmDialog
      :open="confirmation.open"
      :title="confirmation.title"
      :message="confirmation.message"
      :confirm-label="confirmation.confirmLabel"
      :dangerous="confirmation.dangerous"
      @confirm="decideConfirmation(true)"
      @cancel="decideConfirmation(false)"
    />
    <header class="page-builder__toolbar">
      <div>
        <p class="page-builder__eyebrow">{{ t('page_builder_eyebrow') }}</p>
        <h1>{{ t('page_builder_title') }}</h1>
        <p v-if="draft" class="page-builder__revision">
          {{ t('page_builder_revision', { revision: String(draft.entity_revision_id) }) }}
        </p>
      </div>
      <div class="page-builder__toolbar-actions">
        <div class="page-builder__viewport" role="group" :aria-label="t('page_builder_preview_size')">
          <button
            v-for="size in (['desktop', 'tablet', 'mobile'] as PreviewSize[])"
            :key="size"
            type="button"
            class="page-builder__icon-button"
            :class="{ 'is-active': previewSize === size }"
            :aria-pressed="previewSize === size"
            @click="previewSize = size"
          >
            {{ t(`page_builder_${size}`) }}
          </button>
        </div>
        <button type="button" class="btn" :disabled="saving || !draft" @click="refreshPreview">
          {{ t('page_builder_refresh_preview') }}
        </button>
        <button type="button" class="btn" :aria-expanded="historyOpen" :disabled="saving || !draft" @click="toggleHistory">
          {{ t('page_builder_history') }}
        </button>
      </div>
    </header>

    <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    <div v-if="conflict" class="page-builder__conflict" role="alert" data-page-builder-conflict>
      <div>
        <strong>{{ t('page_builder_conflict_title') }}</strong>
        <span>{{ t('page_builder_conflict_help') }}</span>
        <small>{{ conflict.detail }}</small>
      </div>
      <button v-if="!conflict.latestLoaded" type="button" class="btn" :disabled="saving" @click="loadConflictComparison">
        {{ t('page_builder_load_latest_compare') }}
      </button>
      <template v-else>
        <button type="button" class="btn btn-primary" :disabled="saving" @click="reapplyConflict">
          {{ t('page_builder_reapply_change') }}
        </button>
        <button type="button" class="btn" :disabled="saving" @click="keepLatestAfterConflict">
          {{ t('page_builder_keep_latest') }}
        </button>
      </template>
    </div>
    <div
      v-if="advisoryReview"
      ref="advisoryBanner"
      class="page-builder__advisory"
      role="status"
      aria-live="polite"
      tabindex="-1"
      data-page-builder-advisory
    >
      <div>
        <strong>{{ t('page_builder_advisory_title') }}</strong>
        <span>{{ t('page_builder_advisory_help') }}</span>
        <ul>
          <li v-for="advisory in advisoryReview.advisories" :key="advisory.acknowledgement">
            <strong>{{ advisory.field }}</strong>: {{ advisory.message }}
          </li>
        </ul>
      </div>
      <button
        type="button"
        class="btn btn-primary"
        :disabled="saving"
        data-page-builder-advisory-confirm
        @click="acknowledgeAdvisory"
      >
        {{ t('page_builder_advisory_confirm') }}
      </button>
      <button
        type="button"
        class="btn"
        :disabled="saving"
        data-page-builder-advisory-decline
        @click="declineAdvisory"
      >
        {{ t('page_builder_advisory_decline') }}
      </button>
    </div>
    <div
      v-if="advisoryUnsupported"
      class="page-builder__advisory-unsupported"
      role="alert"
      data-page-builder-advisory-unsupported
    >
      <div>
        <strong>{{ t('page_builder_advisory_unsupported_title') }}</strong>
        <span>{{ t('page_builder_advisory_unsupported_help') }}</span>
        <small>{{ advisoryUnsupported }}</small>
      </div>
    </div>
    <div v-if="error" class="page-builder__error" role="alert">
      <strong>{{ t('page_builder_could_not_continue') }}</strong>
      <span>{{ error }}</span>
      <button type="button" class="btn" @click="load">{{ t('page_builder_reload') }}</button>
    </div>
    <div v-if="loading" class="page-builder__loading">{{ t('page_builder_loading') }}</div>

    <div v-else-if="draft && definitions" class="page-builder__workspace">
      <aside class="page-builder__library" :aria-label="t('page_builder_library')">
        <div class="page-builder__panel-heading">
          <div>
            <span>{{ t('page_builder_add') }}</span>
            <h2>{{ t('page_builder_blocks') }}</h2>
          </div>
        </div>
        <div class="page-builder__block-list">
          <button
            v-for="definition in availableBlocks"
            :key="`${definition.id}:${definition.version}`"
            type="button"
            class="page-builder__block-card"
            :disabled="editingBlocked || draft.document.sections.length === 0"
            @click="addBlock(definition)"
          >
            <span class="page-builder__block-icon" aria-hidden="true">+</span>
            <span>
              <strong>{{ definition.label }}</strong>
            </span>
          </button>
        </div>
        <div class="page-builder__panel-heading page-builder__layout-heading">
          <div>
            <span>{{ t('page_builder_add') }}</span>
            <h2>{{ t('page_builder_add_section') }}</h2>
          </div>
        </div>
        <div class="page-builder__layout-list">
          <button
            v-for="layout in availableSectionLayouts"
            :key="`${layout.id}:${layout.version}`"
            type="button"
            class="page-builder__add-section"
            :disabled="editingBlocked"
            @click="addSection(layout)"
          >
            + {{ layoutLabel(layout.id) }}
          </button>
        </div>
      </aside>

      <main class="page-builder__canvas" :aria-label="t('page_builder_preview')">
        <div class="page-builder__canvas-meta">
          <span><i class="page-builder__status-dot" :class="{ 'is-dirty': configDirty }" />{{ saving ? t('page_builder_saving') : configDirty ? t('page_builder_unsaved') : t('page_builder_saved') }}</span>
          <span>{{ t('page_builder_exact_revision_preview') }}</span>
        </div>
        <div class="page-builder__preview-stage">
          <iframe
            v-if="previewUrl"
            data-page-builder-preview
            class="page-builder__preview-frame"
            :style="{ width: previewWidth, minWidth: '0px' }"
            :src="previewUrl"
            :title="t('page_builder_preview')"
          />
          <div v-else class="page-builder__preview-empty">
            <strong>{{ t('page_builder_preview_unavailable') }}</strong>
            <p>{{ t('page_builder_preview_unavailable_help') }}</p>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="refreshPreview">
              {{ t('page_builder_load_preview') }}
            </button>
          </div>
        </div>
      </main>

      <aside class="page-builder__inspector" :aria-label="t('page_builder_inspector')">
        <section v-if="historyOpen" class="page-builder__history" :aria-label="t('page_builder_history')">
          <div class="page-builder__panel-heading">
            <div><span>{{ t('page_builder_compare') }}</span><h2>{{ t('page_builder_revision_history') }}</h2></div>
            <button type="button" class="page-builder__close" :aria-label="t('page_builder_close_history')" @click="historyOpen = false">×</button>
          </div>
          <p class="page-builder__hint">{{ t('page_builder_history_help') }}</p>
          <div class="page-builder__revision-list">
            <button
              v-for="revision in revisions"
              :key="revision.revision_id"
              type="button"
              class="page-builder__revision-card"
              :class="{ 'is-selected': comparedRevision?.entity_revision_id === revision.revision_id }"
              @click="compareRevision(revision.revision_id)"
            >
              <strong>{{ t('page_builder_revision', { revision: String(revision.revision_id) }) }}</strong>
              <span>{{ revision.created_at ? new Date(revision.created_at).toLocaleString() : t('page_builder_time_unknown') }}</span>
              <small>{{ revision.block_count }} {{ t('page_builder_blocks').toLowerCase() }}<template v-if="revision.is_latest"> · {{ t('page_builder_latest') }}</template></small>
            </button>
          </div>
          <div v-if="comparedRevision" class="page-builder__comparison">
            <h3>{{ t('page_builder_compare_with_current') }}</h3>
            <p v-if="comparisonChanges.length === 0" class="page-builder__hint">{{ t('page_builder_no_layout_changes') }}</p>
            <ul v-else>
              <li v-for="change in comparisonChanges" :key="change.id"><strong>{{ t(`page_builder_${change.kind}`) }}:</strong> {{ change.label }}</li>
            </ul>
            <button type="button" class="btn btn-primary" :disabled="editingBlocked || comparedRevision.entity_revision_id === draft.entity_revision_id" @click="restoreComparedRevision">
              {{ t('page_builder_restore_as_draft') }}
            </button>
          </div>
        </section>
        <template v-else>
        <div class="page-builder__panel-heading">
          <div>
            <span>{{ t('page_builder_edit') }}</span>
            <h2>{{ selectedDefinition?.label ?? t('page_builder_nothing_selected') }}</h2>
          </div>
        </div>

        <form v-if="selectedEntry" class="page-builder__form" @submit.prevent="saveSelectedBlock(false)">
          <div v-for="(schema, key) in configProperties" :key="key" class="page-builder__field">
            <WidgetsRichText
              v-if="schema['x-widget'] === 'richtext'"
              :id="configFieldId(key)"
              :model-value="String(fieldValue(key))"
              :label="schema.title ?? key"
              :description="schema.description"
              :required="configFieldRequired(key)"
              :disabled="editingBlocked"
              :schema="entityReferenceSchema(schema)"
              @update:model-value="setConfigValue(key, $event)"
            />
            <WidgetsEntityAutocomplete
              v-else-if="schema['x-widget'] === 'entity_autocomplete'"
              :input-id="configFieldId(key)"
              :model-value="String(fieldValue(key))"
              :label="schema.title ?? key"
              :description="schema.description"
              :required="configFieldRequired(key)"
              :disabled="editingBlocked"
              :schema="entityReferenceSchema(schema)"
              @update:model-value="setConfigValue(key, $event)"
            />
            <template v-else>
              <label :for="configFieldId(key)">{{ schema.title ?? key }}</label>
              <small v-if="schema.description">{{ schema.description }}</small>
              <select
                v-if="schema.enum"
                :id="configFieldId(key)"
                :value="selectValue(key)"
                :required="configFieldRequired(key)"
                :disabled="editingBlocked"
                @change="setFieldValue(key, schema, $event)"
              >
                <option v-for="option in schema.enum" :key="String(option)" :value="String(option)">{{ option }}</option>
              </select>
              <input
                v-else-if="schema.type === 'boolean'"
                :id="configFieldId(key)"
                type="checkbox"
                :checked="fieldValue(key) === true"
                :required="configFieldRequired(key)"
                :disabled="editingBlocked"
                @change="setFieldValue(key, schema, $event)"
              >
              <input
                v-else-if="schema.type === 'number' || schema.type === 'integer'"
                :id="configFieldId(key)"
                type="number"
                :value="selectValue(key)"
                :required="configFieldRequired(key)"
                :disabled="editingBlocked"
                @input="setFieldValue(key, schema, $event)"
              >
              <textarea
                v-else
                :id="configFieldId(key)"
                :rows="schema.format === 'textarea' ? 7 : 3"
                :value="selectValue(key)"
                :required="configFieldRequired(key)"
                :disabled="editingBlocked"
                @input="setFieldValue(key, schema, $event)"
              />
            </template>
          </div>
          <p v-if="Object.keys(configProperties).length === 0" class="page-builder__hint">
            {{ t('page_builder_no_editable_settings') }}
          </p>
          <button type="submit" class="btn btn-primary" :disabled="editingBlocked">
            {{ saving ? t('page_builder_saving') : t('page_builder_apply_change') }}
          </button>
          <div class="page-builder__block-actions" role="group" :aria-label="t('page_builder_reorder_block')">
            <button type="button" class="btn" :disabled="editingBlocked || selectedEntry.position === 0" @click="moveSelectedBlock(-1)">
              {{ t('page_builder_move_up') }}
            </button>
            <button
              type="button"
              class="btn"
              :disabled="editingBlocked || selectedEntry.position >= (selectedEntry.section.regions[selectedEntry.regionId]?.length ?? 0) - 1"
              @click="moveSelectedBlock(1)"
            >
              {{ t('page_builder_move_down') }}
            </button>
          </div>
          <div v-if="blockDestinations.length > 1" class="page-builder__destination">
            <label for="page-builder-block-destination">{{ t('page_builder_move_to_region') }}</label>
            <select id="page-builder-block-destination" v-model="blockDestination" data-block-destination :disabled="editingBlocked">
              <option v-for="destination in blockDestinations" :key="destination.value" :value="destination.value">
                {{ destination.label }}
              </option>
            </select>
            <button
              type="button"
              class="btn"
              data-move-block-to-region
              :disabled="editingBlocked || blockDestination === `${selectedEntry.section.id}::${selectedEntry.regionId}`"
              @click="moveSelectedBlockToRegion"
            >
              {{ t('page_builder_move_block') }}
            </button>
          </div>
          <button type="button" class="btn" :disabled="editingBlocked" @click="duplicateSelectedBlock">
            {{ t('page_builder_duplicate_block') }}
          </button>
          <button type="button" class="btn btn-danger" :disabled="editingBlocked" @click="removeSelectedBlock">
            {{ t('page_builder_remove_block') }}
          </button>
        </form>
        <section v-else-if="selectedSection" class="page-builder__section-controls" :aria-label="t('page_builder_section_settings')">
          <div class="page-builder__panel-heading">
            <div>
              <span>{{ t('page_builder_section') }}</span>
              <h2>{{ layoutLabel(selectedSection.layout.id) }}</h2>
            </div>
          </div>
          <div class="page-builder__block-actions" role="group" :aria-label="t('page_builder_reorder_section')">
            <button type="button" class="btn" data-move-section-up :disabled="editingBlocked || selectedSectionIndex <= 0" @click="moveSelectedSection(-1)">
              {{ t('page_builder_move_up') }}
            </button>
            <button type="button" class="btn" data-move-section-down :disabled="editingBlocked || selectedSectionIndex >= sections.length - 1" @click="moveSelectedSection(1)">
              {{ t('page_builder_move_down') }}
            </button>
          </div>
          <div class="page-builder__field">
            <label for="page-builder-section-layout">{{ t('page_builder_section_layout') }}</label>
            <select id="page-builder-section-layout" v-model="sectionLayoutChoice" data-section-layout :disabled="editingBlocked">
              <option v-for="layout in availableSectionLayouts" :key="`${layout.id}:${layout.version}`" :value="`${layout.id}::${layout.version}`">
                {{ layoutLabel(layout.id) }}
              </option>
            </select>
          </div>
          <button
            type="button"
            class="btn"
            data-change-section-layout
            :disabled="editingBlocked || sectionLayoutChoice === `${selectedSection.layout.id}::${selectedSection.layout.version}`"
            @click="changeSelectedSectionLayout"
          >
            {{ t('page_builder_apply_layout') }}
          </button>
          <button type="button" class="btn" data-duplicate-section :disabled="editingBlocked" @click="duplicateSelectedSection">
            {{ t('page_builder_duplicate_section') }}
          </button>
          <button type="button" class="btn btn-danger" data-remove-section :disabled="editingBlocked || sections.length <= 1" @click="removeSelectedSection">
            {{ t('page_builder_remove_section') }}
          </button>
        </section>
        <p v-else class="page-builder__hint">{{ t('page_builder_select_help') }}</p>

        <div class="page-builder__outline">
          <h3>{{ t('page_builder_outline') }}</h3>
          <div v-for="section in draft.document.sections" :key="section.id" class="page-builder__section">
            <button
              type="button"
              class="page-builder__section-select"
              :class="{ 'is-selected': section.id === selectedSectionId && !selectedBlockId }"
              :aria-pressed="section.id === selectedSectionId && !selectedBlockId"
              :aria-label="t('page_builder_section_outline_label', { position: String(sectionPosition(section)), count: String(sectionBlockCount(section)), layout: layoutLabel(section.layout.id) })"
              :data-section-select="section.id"
              :disabled="saving"
              @click="selectSection(section.id)"
            >
              {{ t('page_builder_section_outline', { position: String(sectionPosition(section)), count: String(sectionBlockCount(section)), layout: layoutLabel(section.layout.id) }) }}
            </button>
            <template v-for="(regionBlocks, regionId) in section.regions" :key="regionId">
              <span class="page-builder__region">{{ regionId }}</span>
              <button
                v-for="block in regionBlocks"
                :key="block.id"
                type="button"
                class="page-builder__outline-block"
                :class="{ 'is-selected': block.id === selectedBlockId }"
                :aria-pressed="block.id === selectedBlockId"
                :data-block-select="block.id"
                :disabled="saving"
                @click="selectBlock(block.id)"
              >
                {{ definitions.blocks.find(item => item.id === block.type)?.label ?? block.type }}
              </button>
            </template>
          </div>
        </div>
        </template>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.page-builder { min-height: calc(100vh - var(--topbar-height)); margin: -24px; background: var(--color-bg, #f0eee8); color: var(--color-text, #17211d); }
.page-builder__toolbar { min-height: 96px; display: flex; align-items: center; justify-content: space-between; gap: 24px; padding: 16px 24px; background: #fff; border-bottom: 1px solid #d9ddd8; }
.page-builder__toolbar h1 { font-size: 24px; line-height: 1.1; }
.page-builder__eyebrow, .page-builder__panel-heading span { color: var(--color-primary, #39705e); font-size: 11px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
.page-builder__revision { margin-top: 5px; color: #6a746f; font-size: 13px; }
.page-builder__toolbar-actions, .page-builder__viewport { display: flex; align-items: center; gap: 8px; }
.page-builder__viewport { padding: 4px; border: 1px solid #d7ddd8; border-radius: 9px; background: #f5f6f3; }
.page-builder__icon-button { min-height: 34px; padding: 0 11px; border: 0; border-radius: 6px; background: transparent; color: #59645f; cursor: pointer; }
.page-builder__icon-button.is-active { background: #fff; color: var(--color-primary, #155b49); box-shadow: 0 1px 4px #14271c1a; font-weight: 700; }
.page-builder__workspace { display: grid; grid-template-columns: 230px minmax(460px, 1fr) 290px; min-height: calc(100vh - var(--topbar-height) - 96px); }
.page-builder__library, .page-builder__inspector { padding: 20px 16px; background: #fbfbf8; overflow-y: auto; }
.page-builder__library { border-right: 1px solid #d9ddd8; }
.page-builder__inspector { border-left: 1px solid #d9ddd8; }
.page-builder__panel-heading { display: flex; justify-content: space-between; align-items: start; margin-bottom: 16px; }
.page-builder__panel-heading h2 { margin-top: 3px; font-size: 17px; }
.page-builder__block-list { display: grid; gap: 8px; }
.page-builder__block-card { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 58px; padding: 10px; border: 1px solid #dfe2dd; border-radius: 9px; background: #fff; color: inherit; text-align: left; cursor: pointer; }
.page-builder__block-card:hover { border-color: #51927f; box-shadow: 0 4px 14px #243b3012; }
.page-builder__block-card span:last-child { display: grid; gap: 2px; }
.page-builder__block-card small { color: #75807b; }
.page-builder__block-icon { display: grid; flex: 0 0 30px; width: 30px; height: 30px; place-items: center; border-radius: 7px; background: var(--color-primary-light, #e2f1eb); color: var(--color-primary, #12624d); font-size: 20px; }
.page-builder__layout-heading { margin-top: 24px; }
.page-builder__layout-list { display: grid; gap: 8px; }
.page-builder__add-section { width: 100%; padding: 11px; border: 1px dashed #8fa49b; border-radius: 8px; background: transparent; color: var(--color-primary, #245c4b); cursor: pointer; }
.page-builder__canvas { min-width: 0; padding: 18px 20px; background: #e8e6df; }
.page-builder__canvas-meta { display: flex; justify-content: space-between; margin-bottom: 10px; color: #5f6964; font-size: 12px; }
.page-builder__canvas-meta span { display: flex; align-items: center; gap: 7px; }
.page-builder__status-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--color-success, #1f8a63); }
.page-builder__status-dot.is-dirty { background: #c97916; }
.page-builder__preview-stage { display: flex; justify-content: center; height: calc(100vh - var(--topbar-height) - 150px); min-height: 520px; overflow: auto; }
.page-builder__preview-frame { max-width: 100%; height: 100%; border: 0; border-radius: 5px; background: #fff; box-shadow: 0 10px 40px #1b2b2426; transition: width .2s ease; }
.page-builder__preview-empty { display: grid; place-content: center; justify-items: center; width: min(100%, 680px); padding: 40px; border: 1px dashed #a8b0aa; border-radius: 8px; background: #f7f7f3; text-align: center; }
.page-builder__preview-empty p { max-width: 38ch; margin: 8px 0 18px; color: #6a746f; }
.page-builder__form, .page-builder__section-controls { display: grid; gap: 13px; }
.page-builder__block-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.page-builder__destination { display: grid; gap: 6px; padding: 10px; border: 1px solid #dfe2dd; border-radius: 8px; background: #f5f6f3; font-size: 13px; font-weight: 700; }
.page-builder__destination select { width: 100%; padding: 9px 10px; border: 1px solid #cbd2cd; border-radius: 7px; background: #fff; color: inherit; font: inherit; font-weight: 400; }
.page-builder__field { display: grid; gap: 6px; font-size: 13px; font-weight: 700; }
.page-builder__field > label { font-weight: 700; }
.page-builder__field small { color: #6a746f; font-weight: 400; }
.page-builder__field input:not([type='checkbox']), .page-builder__field textarea, .page-builder__field select { width: 100%; padding: 9px 10px; border: 1px solid #cbd2cd; border-radius: 7px; background: #fff; color: inherit; font: inherit; font-weight: 400; }
.page-builder__field textarea { resize: vertical; }
.page-builder__hint { color: #6a746f; font-size: 13px; line-height: 1.5; }
.page-builder__outline { margin-top: 28px; padding-top: 18px; border-top: 1px solid #dfe2dd; }
.page-builder__outline h3 { margin-bottom: 12px; font-size: 14px; }
.page-builder__section { display: grid; gap: 5px; margin-bottom: 12px; padding: 9px; border: 1px solid #e1e4df; border-radius: 8px; background: #fff; font-size: 12px; }
.page-builder__section-select { width: 100%; padding: 5px 7px; border: 1px solid transparent; border-radius: 6px; background: transparent; color: inherit; font: inherit; font-weight: 800; text-align: left; cursor: pointer; }
.page-builder__section-select:hover, .page-builder__section-select.is-selected { border-color: var(--color-primary, #2f8068); background: var(--color-primary-light, #e5f2ed); color: var(--color-primary, #125441); }
.page-builder__region { color: #75807b; font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
.page-builder__outline-block { padding: 7px 8px; border: 1px solid transparent; border-radius: 6px; background: #f1f3ef; text-align: left; cursor: pointer; }
.page-builder__outline-block.is-selected { border-color: var(--color-primary, #2f8068); background: var(--color-primary-light, #e5f2ed); color: var(--color-primary, #125441); font-weight: 700; }
.page-builder__close { border: 0; background: transparent; font-size: 24px; cursor: pointer; }
.page-builder__revision-list { display: grid; gap: 7px; max-height: 320px; overflow: auto; }
.page-builder__revision-card { display: grid; gap: 3px; width: 100%; padding: 10px; border: 1px solid #dfe2dd; border-radius: 8px; background: #fff; color: inherit; text-align: left; cursor: pointer; }
.page-builder__revision-card span, .page-builder__revision-card small { color: #6a746f; font-size: 11px; }
.page-builder__revision-card.is-selected { border-color: var(--color-primary, #2f8068); background: var(--color-primary-light, #e5f2ed); }
.page-builder__comparison { display: grid; gap: 10px; margin-top: 16px; padding-top: 14px; border-top: 1px solid #dfe2dd; }
.page-builder__comparison ul { display: grid; gap: 5px; margin: 0; padding-left: 18px; font-size: 12px; }
.page-builder__error, .page-builder__conflict, .page-builder__advisory, .page-builder__advisory-unsupported, .page-builder__loading { margin: 18px 24px; padding: 14px 16px; border-radius: 8px; }
.page-builder__error { display: flex; align-items: center; gap: 10px; background: #fff0ed; color: #8f251a; }
.page-builder__error span { flex: 1; }
.page-builder__conflict { display: flex; align-items: center; gap: 10px; background: #fff6dc; color: #684800; }
.page-builder__conflict > div { display: grid; flex: 1; gap: 3px; }
.page-builder__conflict small { color: inherit; opacity: .8; }
.page-builder__advisory { display: flex; align-items: flex-start; gap: 10px; background: #fff6dc; color: #684800; }
.page-builder__advisory > div { display: grid; flex: 1; gap: 3px; }
.page-builder__advisory ul { margin: 4px 0 0; padding-left: 18px; }
.page-builder__advisory-unsupported { background: #f3f4f6; color: #374151; }
.page-builder__advisory-unsupported > div { display: grid; gap: 3px; }
.page-builder__advisory-unsupported small { color: inherit; opacity: .8; }
.page-builder__loading { background: #fff; color: #5f6964; }
@media (max-width: 1100px) {
  .page-builder__workspace { grid-template-columns: 190px minmax(400px, 1fr) 250px; }
  .page-builder__icon-button { padding-inline: 7px; font-size: 12px; }
}
@media (max-width: 820px) {
  .page-builder { margin: -16px; }
  .page-builder__toolbar { align-items: start; flex-direction: column; }
  .page-builder__workspace { display: flex; flex-direction: column; }
  .page-builder__canvas { order: -1; }
  .page-builder__library, .page-builder__inspector { border: 0; border-top: 1px solid #d9ddd8; }
  .page-builder__block-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>

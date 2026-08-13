<script setup lang="ts">
import type {
  PageBuilderBlock,
  PageBuilderBlockDefinition,
  PageBuilderCommand,
  PageBuilderSection,
} from '~/contracts/pageBuilder'
import { usePageBuilder } from '~/composables/usePageBuilder'

const props = defineProps<{
  surface: string
  entityId: string
}>()

type PreviewSize = 'desktop' | 'tablet' | 'mobile'
type JsonSchemaProperty = {
  type?: string
  format?: string
  title?: string
  description?: string
  enum?: unknown[]
  default?: unknown
}

const { t } = useLanguage()
const { definitions, draft, previewUrl, loading, saving, error, load, apply, refreshPreview } = usePageBuilder(
  props.surface,
  props.entityId,
)
const selectedBlockId = ref<string | null>(null)
const previewSize = ref<PreviewSize>('desktop')
const editableConfig = ref<Record<string, unknown>>({})
const announcement = ref('')

function cloneConfig(config: Record<string, unknown>): Record<string, unknown> {
  return JSON.parse(JSON.stringify(config)) as Record<string, unknown>
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

watch(selectedEntry, (entry) => {
  editableConfig.value = entry ? cloneConfig(entry.block.config) : {}
}, { immediate: true })

function secureId(prefix: 'blk' | 'sec'): string {
  if (typeof crypto === 'undefined' || typeof crypto.randomUUID !== 'function') {
    throw new Error(t('page_builder_secure_id_unavailable'))
  }
  return `${prefix}_${crypto.randomUUID().replaceAll('-', '')}`
}

function selectBlock(blockId: string) {
  selectedBlockId.value = blockId
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
}

async function run(command: PageBuilderCommand, message: string): Promise<boolean> {
  const saved = await apply(command)
  if (!saved) return false
  announcement.value = message
  await refreshPreview()
  return true
}

async function saveSelectedBlock() {
  if (!selectedEntry.value) return
  await run({
    type: 'configure_block',
    block_id: selectedEntry.value.block.id,
    config: cloneConfig(editableConfig.value),
  }, t('page_builder_change_saved'))
}

async function addBlock(definition: PageBuilderBlockDefinition) {
  const section = draft.value?.document.sections[0]
  if (!section) return
  const regionId = Object.keys(section.regions)[0]
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
    position: section.regions[regionId]?.length ?? 0,
    block: { id: blockId, type: definition.id, version: definition.version, config },
  }, t('page_builder_block_added', { block: definition.label }))
  if (saved) selectedBlockId.value = blockId
}

async function addSection() {
  const layout = definitions.value?.layouts[0]
  if (!layout || !draft.value) return
  const regions = Object.fromEntries(layout.regions.map(region => [region, []]))
  await run({
    type: 'add_section',
    position: draft.value.document.sections.length,
    section: { id: secureId('sec'), layout: { id: layout.id, version: layout.version }, regions },
  }, t('page_builder_section_added'))
}

async function removeSelectedBlock() {
  if (!selectedEntry.value) return
  const blockId = selectedEntry.value.block.id
  if (await run({ type: 'remove_block', block_id: blockId }, t('page_builder_block_removed'))) {
    selectedBlockId.value = null
  }
}

async function moveSelectedBlock(offset: -1 | 1) {
  const entry = selectedEntry.value
  /* v8 ignore next -- the controls only render while selectedEntry exists */
  if (!entry) return
  const destination = entry.position + offset
  const regionBlocks = entry.section.regions[entry.regionId] ?? []
  if (destination < 0 || destination >= regionBlocks.length) return
  await run({
    type: 'move_block',
    block_id: entry.block.id,
    destination_section_id: entry.section.id,
    destination_region_id: entry.regionId,
    position: destination,
  }, t('page_builder_block_moved'))
}

async function duplicateSelectedBlock() {
  const entry = selectedEntry.value
  if (!entry) return
  const duplicateId = secureId('blk')
  if (await run({
    type: 'duplicate_block',
    source_block_id: entry.block.id,
    duplicate_block_id: duplicateId,
  }, t('page_builder_block_duplicated'))) {
    selectedBlockId.value = duplicateId
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
  if (blocks.value[0]) selectedBlockId.value = blocks.value[0].block.id
  await refreshPreview()
})
onBeforeUnmount(() => window.removeEventListener('message', onPreviewMessage))
</script>

<template>
  <section class="page-builder" :aria-busy="loading || saving">
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
      </div>
    </header>

    <p class="sr-only" aria-live="polite">{{ announcement }}</p>
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
            v-for="definition in definitions.blocks"
            :key="`${definition.id}:${definition.version}`"
            type="button"
            class="page-builder__block-card"
            :disabled="saving || draft.document.sections.length === 0"
            @click="addBlock(definition)"
          >
            <span class="page-builder__block-icon" aria-hidden="true">+</span>
            <span>
              <strong>{{ definition.label }}</strong>
              <small>{{ definition.id }}</small>
            </span>
          </button>
        </div>
        <button type="button" class="page-builder__add-section" :disabled="saving" @click="addSection">
          + {{ t('page_builder_add_section') }}
        </button>
      </aside>

      <main class="page-builder__canvas" :aria-label="t('page_builder_preview')">
        <div class="page-builder__canvas-meta">
          <span><i class="page-builder__status-dot" />{{ saving ? t('page_builder_saving') : t('page_builder_saved') }}</span>
          <span>{{ t('page_builder_exact_revision_preview') }}</span>
        </div>
        <div class="page-builder__preview-stage">
          <iframe
            v-if="previewUrl"
            data-page-builder-preview
            class="page-builder__preview-frame"
            :style="{ width: previewWidth }"
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
        <div class="page-builder__panel-heading">
          <div>
            <span>{{ t('page_builder_edit') }}</span>
            <h2>{{ selectedDefinition?.label ?? t('page_builder_nothing_selected') }}</h2>
          </div>
        </div>

        <form v-if="selectedEntry" class="page-builder__form" @submit.prevent="saveSelectedBlock">
          <label v-for="(schema, key) in configProperties" :key="key" class="page-builder__field">
            <span>{{ schema.title ?? key }}</span>
            <small v-if="schema.description">{{ schema.description }}</small>
            <select
              v-if="schema.enum"
              :value="selectValue(key)"
              @change="setFieldValue(key, schema, $event)"
            >
              <option v-for="option in schema.enum" :key="String(option)" :value="String(option)">{{ option }}</option>
            </select>
            <input
              v-else-if="schema.type === 'boolean'"
              type="checkbox"
              :checked="fieldValue(key) === true"
              @change="setFieldValue(key, schema, $event)"
            >
            <input
              v-else-if="schema.type === 'number' || schema.type === 'integer'"
              type="number"
              :value="selectValue(key)"
              @input="setFieldValue(key, schema, $event)"
            >
            <textarea
              v-else
              :rows="schema.format === 'textarea' ? 7 : 3"
              :value="selectValue(key)"
              @input="setFieldValue(key, schema, $event)"
            />
          </label>
          <p v-if="Object.keys(configProperties).length === 0" class="page-builder__hint">
            {{ t('page_builder_no_editable_settings') }}
          </p>
          <button type="submit" class="btn btn-primary" :disabled="saving">
            {{ saving ? t('page_builder_saving') : t('page_builder_apply_change') }}
          </button>
          <div class="page-builder__block-actions" role="group" :aria-label="t('page_builder_reorder_block')">
            <button type="button" class="btn" :disabled="saving || selectedEntry.position === 0" @click="moveSelectedBlock(-1)">
              {{ t('page_builder_move_up') }}
            </button>
            <button
              type="button"
              class="btn"
              :disabled="saving || selectedEntry.position >= (selectedEntry.section.regions[selectedEntry.regionId]?.length ?? 0) - 1"
              @click="moveSelectedBlock(1)"
            >
              {{ t('page_builder_move_down') }}
            </button>
          </div>
          <button type="button" class="btn" :disabled="saving" @click="duplicateSelectedBlock">
            {{ t('page_builder_duplicate_block') }}
          </button>
          <button type="button" class="btn btn-danger" :disabled="saving" @click="removeSelectedBlock">
            {{ t('page_builder_remove_block') }}
          </button>
        </form>
        <p v-else class="page-builder__hint">{{ t('page_builder_select_help') }}</p>

        <div class="page-builder__outline">
          <h3>{{ t('page_builder_outline') }}</h3>
          <div v-for="section in draft.document.sections" :key="section.id" class="page-builder__section">
            <strong>{{ section.layout.id }}</strong>
            <template v-for="(regionBlocks, regionId) in section.regions" :key="regionId">
              <span class="page-builder__region">{{ regionId }}</span>
              <button
                v-for="block in regionBlocks"
                :key="block.id"
                type="button"
                class="page-builder__outline-block"
                :class="{ 'is-selected': block.id === selectedBlockId }"
                :aria-pressed="block.id === selectedBlockId"
                @click="selectBlock(block.id)"
              >
                {{ definitions.blocks.find(item => item.id === block.type)?.label ?? block.type }}
              </button>
            </template>
          </div>
        </div>
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
.page-builder__add-section { width: 100%; margin-top: 12px; padding: 11px; border: 1px dashed #8fa49b; border-radius: 8px; background: transparent; color: var(--color-primary, #245c4b); cursor: pointer; }
.page-builder__canvas { min-width: 0; padding: 18px 20px; background: #e8e6df; }
.page-builder__canvas-meta { display: flex; justify-content: space-between; margin-bottom: 10px; color: #5f6964; font-size: 12px; }
.page-builder__canvas-meta span { display: flex; align-items: center; gap: 7px; }
.page-builder__status-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--color-success, #1f8a63); }
.page-builder__preview-stage { display: flex; justify-content: center; height: calc(100vh - var(--topbar-height) - 150px); min-height: 520px; overflow: auto; }
.page-builder__preview-frame { max-width: 100%; height: 100%; border: 0; border-radius: 5px; background: #fff; box-shadow: 0 10px 40px #1b2b2426; transition: width .2s ease; }
.page-builder__preview-empty { display: grid; place-content: center; justify-items: center; width: min(100%, 680px); padding: 40px; border: 1px dashed #a8b0aa; border-radius: 8px; background: #f7f7f3; text-align: center; }
.page-builder__preview-empty p { max-width: 38ch; margin: 8px 0 18px; color: #6a746f; }
.page-builder__form { display: grid; gap: 13px; }
.page-builder__block-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.page-builder__field { display: grid; gap: 6px; font-size: 13px; font-weight: 700; }
.page-builder__field small { color: #6a746f; font-weight: 400; }
.page-builder__field input:not([type='checkbox']), .page-builder__field textarea, .page-builder__field select { width: 100%; padding: 9px 10px; border: 1px solid #cbd2cd; border-radius: 7px; background: #fff; color: inherit; font: inherit; font-weight: 400; }
.page-builder__field textarea { resize: vertical; }
.page-builder__hint { color: #6a746f; font-size: 13px; line-height: 1.5; }
.page-builder__outline { margin-top: 28px; padding-top: 18px; border-top: 1px solid #dfe2dd; }
.page-builder__outline h3 { margin-bottom: 12px; font-size: 14px; }
.page-builder__section { display: grid; gap: 5px; margin-bottom: 12px; padding: 9px; border: 1px solid #e1e4df; border-radius: 8px; background: #fff; font-size: 12px; }
.page-builder__region { color: #75807b; font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
.page-builder__outline-block { padding: 7px 8px; border: 1px solid transparent; border-radius: 6px; background: #f1f3ef; text-align: left; cursor: pointer; }
.page-builder__outline-block.is-selected { border-color: var(--color-primary, #2f8068); background: var(--color-primary-light, #e5f2ed); color: var(--color-primary, #125441); font-weight: 700; }
.page-builder__error, .page-builder__loading { margin: 18px 24px; padding: 14px 16px; border-radius: 8px; }
.page-builder__error { display: flex; align-items: center; gap: 10px; background: #fff0ed; color: #8f251a; }
.page-builder__error span { flex: 1; }
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

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import type { SchemaProperty } from '~/composables/useSchema'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import { useAdmin } from '~/composables/useAdmin'
import { useLanguage } from '~/composables/useLanguage'

const props = defineProps<{
  modelValue: string | string[]
  label?: string
  description?: string
  required?: boolean
  disabled?: boolean
  schema?: SchemaProperty
  inputId?: string
  descriptionId?: string
  error?: string
  errorId?: string
  describedBy?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string | string[]] }>()

const { search } = useEntity()
const { getEntity } = useAdmin()
const { t } = useLanguage()

const inputValue = ref('')
const results = ref<JsonApiResource[]>([])
const showDropdown = ref(false)
const searching = ref(false)
const searchError = ref<string | null>(null)
const selectedLabel = ref('')
const selectedItems = ref<Array<{ id: string; label: string | null }>>([])
const activeIndex = ref(-1)
const instanceId = useId()
const inputId = props.inputId ?? `entity-autocomplete-${instanceId}`
const listboxId = `${inputId}-listbox`

const targetType = computed(() => props.schema?.['x-target-type'] ?? '')
const multiple = computed(() => {
  const cardinality = props.schema?.['x-cardinality']
  return typeof cardinality === 'number' && cardinality !== 1
})

type ReferenceMetadata = {
  labelField: string
  search: { field: string; operator: 'STARTS_WITH' }
  sort: { field: string; direction: 'ASC' } | null
}

const reference = computed<ReferenceMetadata | null>(() => {
  if (targetType.value === '') return null
  const raw = getEntity(targetType.value)?.reference
  if (!raw || typeof raw.labelField !== 'string' || raw.labelField.trim() === '') return null
  if (!raw.search || typeof raw.search.field !== 'string' || raw.search.field.trim() === '') return null
  if (raw.search.operator !== 'STARTS_WITH') return null
  if (raw.sort !== null && (
    typeof raw.sort?.field !== 'string'
    || raw.sort.field.trim() === ''
    || raw.sort.direction !== 'ASC'
  )) return null
  return {
    labelField: raw.labelField,
    search: { field: raw.search.field, operator: raw.search.operator },
    sort: raw.sort === null ? null : { field: raw.sort.field, direction: raw.sort.direction },
  }
})

const activeDescendant = computed(() => {
  const result = results.value[activeIndex.value]
  return result ? `${listboxId}-option-${activeIndex.value}` : undefined
})

let debounceTimer: ReturnType<typeof setTimeout> | null = null
let requestSequence = 0
let blurTimer: ReturnType<typeof setTimeout> | null = null

function idsFromModel(): string[] {
  if (Array.isArray(props.modelValue)) return props.modelValue.filter((id): id is string => typeof id === 'string')
  return props.modelValue === '' ? [] : [props.modelValue]
}

watch(() => props.modelValue, () => {
  const ids = idsFromModel()
  if (multiple.value) {
    const knownLabels = new Map(selectedItems.value.map(item => [item.id, item.label]))
    selectedItems.value = ids.map(id => ({ id, label: knownLabels.get(id) ?? null }))
    return
  }
  const value = ids[0] ?? ''
  if (value === '') {
    selectedLabel.value = ''
    inputValue.value = ''
  } else if (selectedLabel.value === '') {
    // Do not render an opaque ID as a label. Existing scalar references stay
    // selected in the model while the visible input remains blank.
    inputValue.value = ''
  }
}, { immediate: true })

watch(results, () => {
  activeIndex.value = -1
})

function invalidateSearch() {
  requestSequence++
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer)
    debounceTimer = null
  }
  results.value = []
  activeIndex.value = -1
  searchError.value = null
  searching.value = false
}

function classifyError(error: unknown): string {
  if (error instanceof SyntaxError) return 'autocomplete_malformed_response'
  if (error instanceof TypeError) return 'autocomplete_network_error'
  const status = typeof error === 'object' && error !== null && 'status' in error
    ? Number((error as { status?: unknown }).status)
    : 0
  if (status === 401 || status === 403) return 'autocomplete_forbidden'
  if (status === 404) return 'autocomplete_not_found'
  return 'autocomplete_server_error'
}

function validatedResults(value: unknown, labelField: string): JsonApiResource[] | null {
  if (!Array.isArray(value)) return null
  for (const resource of value) {
    if (
      typeof resource !== 'object'
      || resource === null
      || typeof resource.id !== 'string'
      || typeof resource.attributes !== 'object'
      || resource.attributes === null
      || typeof resource.attributes[labelField] !== 'string'
      || resource.attributes[labelField].trim() === ''
    ) return null
  }
  return value as JsonApiResource[]
}

function onInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  inputValue.value = value
  if (!multiple.value) selectedLabel.value = ''
  invalidateSearch()

  if (value.length < 2) {
    showDropdown.value = false
    return
  }

  showDropdown.value = true
  const metadata = reference.value
  if (metadata === null) {
    searchError.value = t('autocomplete_unavailable')
    return
  }

  searching.value = true
  const sequence = requestSequence
  debounceTimer = setTimeout(async () => {
    debounceTimer = null
    try {
      const response = await search(
        targetType.value,
        metadata.search.field,
        value,
        10,
        metadata.search.operator,
        metadata.sort,
      )
      if (sequence !== requestSequence) return
      const validated = validatedResults(response, metadata.labelField)
      if (validated === null) {
        results.value = []
        searchError.value = t('autocomplete_malformed_response')
        return
      }
      const selectedIds = new Set(selectedItems.value.map(item => item.id))
      results.value = multiple.value
        ? validated.filter(resource => !selectedIds.has(resource.id))
        : validated
    } catch (error: unknown) {
      if (sequence !== requestSequence) return
      results.value = []
      searchError.value = t(classifyError(error))
    } finally {
      if (sequence === requestSequence) searching.value = false
    }
  }, 250)
}

function selectResult(resource: JsonApiResource) {
  const metadata = reference.value
  if (metadata === null) return
  const label = resource.attributes[metadata.labelField]
  if (typeof label !== 'string' || label.trim() === '') return

  if (multiple.value) {
    const currentIds = selectedItems.value.map(item => item.id)
    if (currentIds.includes(resource.id)) return
    const cardinality = props.schema?.['x-cardinality']
    if (typeof cardinality === 'number' && cardinality > 1 && currentIds.length >= cardinality) return
    selectedItems.value = [...selectedItems.value, { id: resource.id, label }]
    emit('update:modelValue', [...currentIds, resource.id])
    inputValue.value = ''
  } else {
    selectedLabel.value = label
    inputValue.value = label
    emit('update:modelValue', resource.id)
  }
  invalidateSearch()
  showDropdown.value = false
}

function removeSelected(id: string) {
  selectedItems.value = selectedItems.value.filter(item => item.id !== id)
  emit('update:modelValue', selectedItems.value.map(item => item.id))
}

function resultLabel(resource: JsonApiResource): string {
  const metadata = reference.value
  return metadata === null ? '' : String(resource.attributes[metadata.labelField] ?? '')
}

function selectedDisplayLabel(item: { id: string; label: string | null }, index: number): string {
  return item.label ?? `${t('autocomplete_selected')} ${index + 1}`
}

function onBlur() {
  blurTimer = setTimeout(() => {
    showDropdown.value = false
  }, 200)
}

function onFocus() {
  if (results.value.length > 0 || searchError.value !== null) showDropdown.value = true
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    showDropdown.value = false
    return
  }
  if (!showDropdown.value || results.value.length === 0) return
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = Math.max(activeIndex.value - 1, 0)
  } else if (event.key === 'Enter' && activeIndex.value >= 0) {
    event.preventDefault()
    const selected = results.value[activeIndex.value]
    if (selected !== undefined) selectResult(selected)
  }
}

function clear() {
  inputValue.value = ''
  selectedLabel.value = ''
  invalidateSearch()
  showDropdown.value = false
  if (!multiple.value) emit('update:modelValue', '')
}

onBeforeUnmount(() => {
  invalidateSearch()
  if (blurTimer !== null) clearTimeout(blurTimer)
})
</script>

<template>
  <div class="field autocomplete-field">
    <label v-if="label" :for="inputId" class="field-label">
      {{ label }}
      <span v-if="required" class="required" aria-hidden="true">*</span>
    </label>
    <div v-if="multiple && selectedItems.length > 0" class="autocomplete-selected">
      <span v-for="(item, index) in selectedItems" :key="item.id" class="autocomplete-selected-item">
        {{ selectedDisplayLabel(item, index) }}
        <button
          type="button"
          class="autocomplete-remove touch-target"
          :aria-label="t('autocomplete_remove', { label: selectedDisplayLabel(item, index) })"
          :disabled="disabled"
          @click="removeSelected(item.id)"
        >&times;</button>
      </span>
    </div>
    <div class="autocomplete-controls">
      <div class="autocomplete-input-wrapper">
        <input
          :id="inputId"
          type="text"
          :value="inputValue"
          :required="required && idsFromModel().length === 0"
          :aria-required="required ? 'true' : undefined"
          :aria-invalid="error ? 'true' : undefined"
          :aria-describedby="describedBy"
          :disabled="disabled"
          :placeholder="t('autocomplete_placeholder')"
          class="field-input touch-target"
          role="combobox"
          :aria-expanded="showDropdown"
          aria-autocomplete="list"
          aria-haspopup="listbox"
          :aria-controls="listboxId"
          :aria-activedescendant="activeDescendant"
          @input="onInput"
          @blur="onBlur"
          @focus="onFocus"
          @keydown="onKeydown"
        >
        <div v-if="showDropdown" :id="listboxId" class="autocomplete-dropdown" role="listbox">
          <div v-if="searching" class="autocomplete-item autocomplete-loading" role="status" aria-live="polite">
            {{ t('autocomplete_loading') }}
          </div>
          <div v-else-if="searchError" class="autocomplete-item autocomplete-error" role="alert" aria-live="assertive">
            {{ searchError }}
          </div>
          <div v-else-if="results.length === 0" class="autocomplete-item autocomplete-empty" role="status" aria-live="polite">
            {{ t('autocomplete_no_results') }}
          </div>
          <button
            v-for="(resource, index) in results"
            :id="`${listboxId}-option-${index}`"
            :key="resource.id"
            type="button"
            class="autocomplete-item touch-target"
            :class="{ 'autocomplete-item--active': index === activeIndex }"
            role="option"
            :aria-selected="index === activeIndex"
            @mousedown.prevent="selectResult(resource)"
          >
            {{ resultLabel(resource) }}
          </button>
        </div>
      </div>
      <button
        v-if="inputValue"
        type="button"
        class="autocomplete-clear touch-target"
        :aria-label="t('delete')"
        :disabled="disabled"
        @mousedown.prevent.stop
        @click.stop="clear"
      >&times;</button>
    </div>
    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error"><strong>Error:</strong> {{ error }}</p>
  </div>
</template>

<style scoped>
.autocomplete-selected { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 0.375rem; }
.autocomplete-selected-item { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.5rem; border-radius: 999px; background: var(--color-bg); }
.autocomplete-remove { display: inline-flex; align-items: center; justify-content: center; border: 0; background: none; cursor: pointer; font-size: 1rem; line-height: 1; }
.autocomplete-controls { display: flex; align-items: start; gap: 0.5rem; min-width: 0; }
.autocomplete-input-wrapper { position: relative; flex: 1 1 auto; min-width: 0; }
.autocomplete-clear { display: inline-flex; flex: 0 0 var(--admin-target-size); align-items: center; justify-content: center; background: none; border: 1px solid var(--color-border); border-radius: 4px; font-size: 18px; color: var(--color-muted); cursor: pointer; padding: 0; line-height: 1; }
.autocomplete-clear:hover { color: var(--color-text); }
.autocomplete-clear:disabled, .autocomplete-remove:disabled { cursor: not-allowed; }
.autocomplete-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: var(--color-surface); border: 1px solid var(--color-border); border-top: none; border-radius: 0 0 4px 4px; max-height: 200px; overflow-y: auto; z-index: 100; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); }
.autocomplete-item { display: block; width: 100%; padding: 8px 12px; text-align: left; border: none; background: none; font-size: 14px; cursor: pointer; color: var(--color-text); font-family: inherit; }
.autocomplete-item:hover { background: var(--color-bg); }
.autocomplete-item--active { background: var(--color-primary); color: #fff; }
.autocomplete-loading, .autocomplete-empty, .autocomplete-error { color: var(--color-muted); cursor: default; font-style: italic; }
.autocomplete-error { color: var(--color-danger, #c0392b); }
</style>

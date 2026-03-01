<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import type { SchemaProperty } from '~/composables/useSchema'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'

const props = defineProps<{
  modelValue: string
  label?: string
  description?: string
  required?: boolean
  disabled?: boolean
  schema?: SchemaProperty
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { search } = useEntity()
const { t } = useLanguage()

const inputValue = ref('')
const results = ref<JsonApiResource[]>([])
const showDropdown = ref(false)
const searching = ref(false)
const searchError = ref<string | null>(null)
const selectedLabel = ref('')
const activeIndex = ref(-1)

// Determine target entity type and label field from schema.
const targetType = computed(() => props.schema?.['x-target-type'] ?? 'node')
const labelField = computed(() => 'title') // Label field varies by entity type; default to title.

let debounceTimer: ReturnType<typeof setTimeout> | null = null

// When modelValue changes externally, update display.
watch(() => props.modelValue, (val) => {
  if (val && !selectedLabel.value) {
    selectedLabel.value = val
    inputValue.value = val
  }
}, { immediate: true })

// Reset active index when results change.
watch(results, () => {
  activeIndex.value = -1
})

function onInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  inputValue.value = value
  selectedLabel.value = ''

  if (debounceTimer) clearTimeout(debounceTimer)

  if (value.length < 2) {
    results.value = []
    showDropdown.value = false
    return
  }

  debounceTimer = setTimeout(async () => {
    searching.value = true
    searchError.value = null
    try {
      results.value = await search(targetType.value, labelField.value, value)
      showDropdown.value = results.value.length > 0 || value.length >= 2
    } catch (e: any) {
      console.error('[Waaseyaa] Autocomplete search failed:', e)
      results.value = []
      searchError.value = e?.data?.errors?.[0]?.detail ?? t('error_generic')
      showDropdown.value = true
    } finally {
      searching.value = false
    }
  }, 250)
}

function selectResult(resource: JsonApiResource) {
  const label = resource.attributes[labelField.value] ?? resource.id
  selectedLabel.value = label
  inputValue.value = label
  emit('update:modelValue', resource.id)
  showDropdown.value = false
  results.value = []
}

function onBlur() {
  // Delay to allow click on dropdown item.
  setTimeout(() => {
    showDropdown.value = false
  }, 200)
}

function onFocus() {
  if (results.value.length > 0) {
    showDropdown.value = true
  }
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
    selectResult(results.value[activeIndex.value])
  }
}

function clear() {
  inputValue.value = ''
  selectedLabel.value = ''
  emit('update:modelValue', '')
  results.value = []
  showDropdown.value = false
}
</script>

<template>
  <div class="field autocomplete-field">
    <label v-if="label" class="field-label">
      {{ label }}
      <span v-if="required" class="required">*</span>
    </label>
    <div class="autocomplete-wrapper">
      <input
        type="text"
        :value="inputValue"
        :required="required"
        :disabled="disabled"
        :placeholder="t('autocomplete_placeholder')"
        class="field-input"
        role="combobox"
        :aria-expanded="showDropdown"
        aria-autocomplete="list"
        aria-haspopup="listbox"
        @input="onInput"
        @blur="onBlur"
        @focus="onFocus"
        @keydown="onKeydown"
      />
      <button
        v-if="inputValue"
        type="button"
        class="autocomplete-clear"
        :aria-label="t('delete')"
        @click="clear"
      >&times;</button>
      <div v-if="showDropdown" class="autocomplete-dropdown" role="listbox">
        <div v-if="searching" class="autocomplete-item autocomplete-loading">
          {{ t('autocomplete_loading') }}
        </div>
        <div v-else-if="searchError" class="autocomplete-item autocomplete-error">
          {{ searchError }}
        </div>
        <div v-else-if="results.length === 0" class="autocomplete-item autocomplete-empty">
          {{ t('autocomplete_no_results') }}
        </div>
        <button
          v-for="(resource, index) in results"
          :key="resource.id"
          type="button"
          class="autocomplete-item"
          :class="{ 'autocomplete-item--active': index === activeIndex }"
          role="option"
          :aria-selected="index === activeIndex"
          @mousedown.prevent="selectResult(resource)"
        >
          {{ resource.attributes[labelField] ?? resource.id }}
        </button>
      </div>
    </div>
    <p v-if="description" class="field-description">{{ description }}</p>
  </div>
</template>

<style scoped>
.autocomplete-wrapper {
  position: relative;
}
.autocomplete-clear {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  font-size: 18px;
  color: var(--color-muted);
  cursor: pointer;
  padding: 0 4px;
  line-height: 1;
}
.autocomplete-clear:hover {
  color: var(--color-text);
}
.autocomplete-dropdown {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-top: none;
  border-radius: 0 0 4px 4px;
  max-height: 200px;
  overflow-y: auto;
  z-index: 100;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.autocomplete-item {
  display: block;
  width: 100%;
  padding: 8px 12px;
  text-align: left;
  border: none;
  background: none;
  font-size: 14px;
  cursor: pointer;
  color: var(--color-text);
  font-family: inherit;
}
.autocomplete-item:hover {
  background: var(--color-bg);
}
.autocomplete-item--active {
  background: var(--color-primary);
  color: #fff;
}
.autocomplete-loading,
.autocomplete-empty,
.autocomplete-error {
  color: var(--color-muted);
  cursor: default;
  font-style: italic;
}
.autocomplete-error {
  color: var(--color-danger, #c0392b);
}
</style>

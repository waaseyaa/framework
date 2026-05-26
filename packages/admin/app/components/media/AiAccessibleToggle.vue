<script setup lang="ts">
/**
 * AiAccessibleToggle — per-file AI accessibility tri-state control.
 *
 * Renders a select element bound to the `ai_accessible` field value.
 * Allowed values: 'yes' | 'no' | 'inherit'. Default: 'inherit'.
 *
 * Uses the SchemaField → WidgetsSelect renderer chain by composing the
 * same schema shape that SchemaField dispatches to WidgetsSelect. This
 * avoids inventing a new field-renderer mechanism (FR-012, admin-spa.md).
 *
 * Refs: gap-matrix-A5, FR-012.
 */

const props = defineProps<{
  modelValue?: 'yes' | 'no' | 'inherit'
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: 'yes' | 'no' | 'inherit'] }>()

const { t } = useLanguage()

/**
 * The resolved value — defaults to 'inherit' when undefined or unset,
 * preserving the access-preserving default (C-004).
 */
const currentValue = computed<'yes' | 'no' | 'inherit'>(() => props.modelValue ?? 'inherit')

const options: Array<{ value: 'yes' | 'no' | 'inherit'; labelKey: string }> = [
  { value: 'inherit', labelKey: 'media_ai_accessible_inherit' },
  { value: 'yes', labelKey: 'media_ai_accessible_yes' },
  { value: 'no', labelKey: 'media_ai_accessible_no' },
]

function handleChange(event: Event): void {
  const target = event.target as HTMLSelectElement
  const val = target.value as 'yes' | 'no' | 'inherit'
  emit('update:modelValue', val)
}
</script>

<template>
  <div class="field">
    <label class="field-label">
      {{ t('media_ai_accessible_label') }}
    </label>
    <select
      :value="currentValue"
      :disabled="disabled"
      class="field-input"
      @change="handleChange"
    >
      <option
        v-for="opt in options"
        :key="opt.value"
        :value="opt.value"
      >
        {{ t(opt.labelKey) }}
      </option>
    </select>
    <p class="field-description">{{ t('media_ai_accessible_help') }}</p>
  </div>
</template>

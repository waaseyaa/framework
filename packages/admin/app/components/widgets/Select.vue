<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

const props = defineProps<{
  modelValue: string | number | Array<string | number>
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

const emit = defineEmits<{
  'update:modelValue': [value: string | number | Array<string | number>]
}>()

const multiple = computed(() => props.schema?.type === 'array')
const itemType = computed(() => multiple.value ? props.schema?.items?.type : props.schema?.type)

function coerceValue(value: string): string | number {
  if (itemType.value === 'integer' || itemType.value === 'number') {
    const numeric = Number(value)
    return Number.isFinite(numeric) ? numeric : value
  }
  return value
}

const options = computed(() => {
  const enumValues = props.schema?.items?.enum ?? props.schema?.enum ?? []
  const labels = props.schema?.['x-enum-labels'] ?? {}
  return enumValues.map((val: string | number) => ({
    value: String(val),
    label: labels[String(val)] ?? String(val),
  }))
})

function onChange(event: Event): void {
  const select = event.target as HTMLSelectElement
  if (multiple.value) {
    emit('update:modelValue', Array.from(select.selectedOptions, option => coerceValue(option.value)))
    return
  }
  emit('update:modelValue', coerceValue(select.value))
}
</script>

<template>
  <div class="field">
    <label v-if="label" class="field-label" :for="inputId">
      {{ label }}
      <span v-if="required" class="required" aria-hidden="true">*</span>
    </label>
    <select
      :id="inputId"
      :value="modelValue"
      :multiple="multiple"
      :required="required"
      :aria-required="required ? 'true' : undefined"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      :disabled="disabled"
      class="field-input touch-target"
      @change="onChange"
    >
      <option v-if="!multiple" value="" disabled>-- Select --</option>
      <option v-for="opt in options" :key="opt.value" :value="opt.value">
        {{ opt.label }}
      </option>
    </select>
    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error"><strong>Error:</strong> {{ error }}</p>
  </div>
</template>

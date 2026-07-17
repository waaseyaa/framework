<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

const props = defineProps<{
  modelValue: string
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

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

// datetime-local inputs require YYYY-MM-DDTHH:MM:SS without timezone offset.
// The API returns ISO 8601 with offset (e.g. "2026-03-08T15:52:48+00:00").
const localValue = computed(() => {
  if (!props.modelValue) return ''
  // Strip timezone offset or trailing Z, keep only YYYY-MM-DDTHH:MM:SS
  return props.modelValue.replace(/([+-]\d{2}:\d{2}|Z)$/, '').slice(0, 19)
})
</script>

<template>
  <div class="field">
    <label v-if="label" class="field-label" :for="inputId">
      {{ label }}
      <span v-if="required" class="required" aria-hidden="true">*</span>
    </label>
    <input
      :id="inputId"
      type="datetime-local"
      :value="localValue"
      :required="required"
      :aria-required="required ? 'true' : undefined"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      :disabled="disabled"
      class="field-input touch-target"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error"><strong>Error:</strong> {{ error }}</p>
  </div>
</template>

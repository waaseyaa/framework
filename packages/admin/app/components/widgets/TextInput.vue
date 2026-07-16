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

const inputType = computed(() => {
  const widget = props.schema?.['x-widget'] ?? 'text'
  if (widget === 'email') return 'email'
  if (widget === 'url') return 'url'
  return 'text'
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
      :type="inputType"
      :value="modelValue"
      :required="required"
      :aria-required="required ? 'true' : undefined"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      :disabled="disabled"
      :maxlength="schema?.maxLength"
      :aria-label="label"
      class="field-input touch-target"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error"><strong>Error:</strong> {{ error }}</p>
  </div>
</template>

<script setup lang="ts">
import { computed, useId } from 'vue'
import type { SchemaProperty } from '~/composables/useSchema'

const props = defineProps<{
  modelValue: string | null
  inputId?: string
  id?: string
  label?: string
  description?: string
  descriptionId?: string
  describedBy?: string
  error?: string
  errorId?: string
  required?: boolean
  disabled?: boolean
  schema?: SchemaProperty
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()
const generatedId = useId()
const inputId = computed(() => props.inputId ?? props.id ?? `date-${generatedId}`)

function onInput(event: Event) {
  const value = (event.target as HTMLInputElement).value
  emit('update:modelValue', value === '' ? null : value)
}
</script>

<template>
  <div class="field">
    <label v-if="label" class="field-label" :for="inputId">
      {{ label }}
      <span v-if="required" class="required" aria-hidden="true">*</span>
      <span v-if="required" class="sr-only"> (required)</span>
    </label>
    <input
      :id="inputId"
      type="date"
      :value="modelValue ?? ''"
      :required="required"
      :disabled="disabled"
      :min="schema?.['x-min']"
      :max="schema?.['x-max']"
      :aria-describedby="describedBy || undefined"
      :aria-invalid="error ? 'true' : undefined"
      class="field-input touch-target"
      @input="onInput"
    >
    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error" role="alert">{{ error }}</p>
  </div>
</template>

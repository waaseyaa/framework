<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

defineProps<{
  modelValue: boolean
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

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <div class="field field-toggle">
    <label class="field-label toggle-label" :for="inputId">
      <input
        :id="inputId"
        type="checkbox"
        :checked="modelValue"
        :disabled="disabled"
        :required="required"
        :aria-required="required ? 'true' : undefined"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="describedBy"
        class="toggle-checkbox"
        @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)"
      >
      <span>{{ label }}</span>
      <span v-if="required" class="required" aria-hidden="true">*</span>
    </label>
    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error"><strong>Error:</strong> {{ error }}</p>
  </div>
</template>

<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'
import { schemaFormContextKey } from '~/components/schema/schemaFormContext'

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

const context = inject(schemaFormContextKey, null)
if (!context) {
  throw new Error('[MachineNameInput] Missing SchemaForm provider context.')
}

const sourceField = props.schema?.['x-source-field']
if (!sourceField) {
  throw new Error('[MachineNameInput] machine_name widgets require x-source-field.')
}

const { formData, isEditMode } = context
const isLocked = computed(() => isEditMode.value || !!props.disabled)

// Auto-generate machine name from source field when not locked and user hasn't manually edited.
const manuallyEdited = ref(false)

function toMachineName(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .substring(0, 128)
}

watch(
  () => formData.value[sourceField],
  (newLabel) => {
    if (isLocked.value || manuallyEdited.value || !newLabel) return
    emit('update:modelValue', toMachineName(String(newLabel)))
  },
  { immediate: true },
)

function onInput(event: Event) {
  const raw = (event.target as HTMLInputElement).value
  manuallyEdited.value = true
  emit('update:modelValue', toMachineName(raw))
}
</script>

<template>
  <div class="field">
    <label v-if="label" class="field-label" :for="inputId">
      {{ label }}
      <span v-if="required" class="required" aria-hidden="true">*</span>
    </label>
    <input
      :id="inputId"
      type="text"
      :value="modelValue"
      :required="required"
      :aria-required="required ? 'true' : undefined"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      :disabled="isLocked"
      :maxlength="128"
      pattern="[a-z0-9_]+"
      class="field-input field-input--machine-name touch-target"
      @input="onInput"
    >
    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error"><strong>Error:</strong> {{ error }}</p>
  </div>
</template>

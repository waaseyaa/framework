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
    <label v-if="label" class="field-label">
      {{ label }}
      <span v-if="required" class="required">*</span>
    </label>
    <input
      type="text"
      :value="modelValue"
      :required="required"
      :disabled="isLocked"
      :maxlength="128"
      pattern="[a-z0-9_]+"
      class="field-input field-input--machine-name"
      @input="onInput"
    />
    <p v-if="description" class="field-description">{{ description }}</p>
  </div>
</template>

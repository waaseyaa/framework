<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

const props = defineProps<{
  modelValue: string
  label?: string
  description?: string
  required?: boolean
  disabled?: boolean
  schema?: SchemaProperty
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const formData = inject<Ref<Record<string, any>> | undefined>('schemaFormData', undefined)
const isEditMode = inject<Ref<boolean> | undefined>('schemaFormEditMode', undefined)

if (import.meta.dev && (!formData || !isEditMode)) {
  console.warn('[MachineNameInput] Missing schemaFormData/schemaFormEditMode provider. Auto-generation disabled.')
}

const sourceField = computed(() => props.schema?.['x-source-field'])
const isLocked = computed(() => isEditMode?.value || props.disabled)

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
  () => sourceField.value && formData ? formData.value[sourceField.value] : undefined,
  (newLabel) => {
    if (isLocked.value || manuallyEdited.value || !newLabel) return
    emit('update:modelValue', toMachineName(String(newLabel)))
  },
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

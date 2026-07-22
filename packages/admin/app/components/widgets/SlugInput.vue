<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'
import { useEntity } from '~/composables/useEntity'
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
if (!context) throw new Error('[SlugInput] Missing SchemaForm provider context.')

const sourceField = props.schema?.['x-source-field']
if (!sourceField) throw new Error('[SlugInput] slug widgets require x-source-field.')

const { runAction } = useEntity()
const manuallyEdited = ref(false)
let generation = 0

watch(
  () => context.formData.value[sourceField],
  async (value) => {
    if (context.isEditMode.value || props.disabled || manuallyEdited.value || !value) return
    const request = ++generation
    try {
      const result = await runAction(context.entityType, 'generate-slug', { value: String(value) }) as { slug?: unknown }
      if (request === generation && typeof result.slug === 'string' && !manuallyEdited.value) {
        emit('update:modelValue', result.slug)
      }
    } catch {
      // Slug generation is an enhancement; keep the field editable if the action is unavailable.
    }
  },
  { immediate: true },
)

function onInput(event: Event) {
  manuallyEdited.value = true
  generation++
  emit('update:modelValue', (event.target as HTMLInputElement).value)
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
      :disabled="disabled"
      class="field-input field-input--slug touch-target"
      @input="onInput"
    >
    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error"><strong>Error:</strong> {{ error }}</p>
  </div>
</template>

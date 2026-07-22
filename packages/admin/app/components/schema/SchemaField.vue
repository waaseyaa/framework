<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

const props = defineProps<{
  name: string
  modelValue: any
  schema: SchemaProperty
  disabled?: boolean
  inputId: string
  required?: boolean
  error?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: any] }>()

const label = computed(() => props.schema['x-label'] ?? props.name)
const description = computed(() => props.schema['x-description'] ?? props.schema.description)
const required = computed(() => props.required === true || props.schema['x-required'] === true)
const isDisabled = computed(() => props.disabled || !!props.schema['x-access-restricted'])
const descriptionId = computed(() => description.value ? `${props.inputId}-description` : undefined)
const errorId = computed(() => props.error ? `${props.inputId}-error` : undefined)
const describedBy = computed(() => [descriptionId.value, errorId.value].filter(Boolean).join(' ') || undefined)

const widgetMap: Record<string, Component> = {
  text: resolveComponent('WidgetsTextInput') as Component,
  email: resolveComponent('WidgetsTextInput') as Component,
  url: resolveComponent('WidgetsTextInput') as Component,
  textarea: resolveComponent('WidgetsTextArea') as Component,
  richtext: resolveComponent('WidgetsRichText') as Component,
  number: resolveComponent('WidgetsNumberInput') as Component,
  boolean: resolveComponent('WidgetsToggle') as Component,
  select: resolveComponent('WidgetsSelect') as Component,
  datetime: resolveComponent('WidgetsDateTimeInput') as Component,
  date: resolveComponent('WidgetsDateInput') as Component,
  entity_autocomplete: resolveComponent('WidgetsEntityAutocomplete') as Component,
  hidden: resolveComponent('WidgetsHiddenField') as Component,
  // The structured "blocks" field has no inline editor yet; keep its value as a
  // hidden field so it round-trips on save instead of rendering as raw JSON.
  blocks: resolveComponent('WidgetsHiddenField') as Component,
  machine_name: resolveComponent('WidgetsMachineNameInput') as Component,
  slug: resolveComponent('WidgetsSlugInput') as Component,
  password: resolveComponent('WidgetsTextInput') as Component,
  image: resolveComponent('WidgetsFileUpload') as Component,
  file: resolveComponent('WidgetsFileUpload') as Component,
}

const fallback = resolveComponent('WidgetsTextInput') as Component

const widgetComponent = computed(() => {
  const widget = props.schema['x-widget'] ?? 'text'
  return widgetMap[widget] ?? fallback
})
const widgetIdProps = computed(() => {
  const widget = props.schema['x-widget'] ?? 'text'
  return widget === 'richtext' || widget === 'date'
    ? { id: props.inputId }
    : { inputId: props.inputId }
})
</script>

<template>
  <component
    :is="widgetComponent"
    v-bind="widgetIdProps"
    :model-value="modelValue"
    :label="label"
    :description="description"
    :description-id="descriptionId"
    :required="required"
    :disabled="isDisabled"
    :error="error"
    :error-id="errorId"
    :described-by="describedBy"
    :schema="schema"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>

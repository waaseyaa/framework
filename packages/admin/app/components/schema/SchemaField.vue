<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

const props = defineProps<{
  name: string
  modelValue: any
  schema: SchemaProperty
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: any] }>()

const label = computed(() => props.schema['x-label'] ?? props.name)
const description = computed(() => props.schema['x-description'] ?? props.schema.description)
const required = computed(() => props.schema['x-required'] ?? false)
const isDisabled = computed(() => props.disabled || (props.schema.readOnly && props.schema['x-access-restricted']))

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
  entity_autocomplete: resolveComponent('WidgetsEntityAutocomplete') as Component,
  hidden: resolveComponent('WidgetsHiddenField') as Component,
  password: resolveComponent('WidgetsTextInput') as Component,
  image: resolveComponent('WidgetsTextInput') as Component,
  file: resolveComponent('WidgetsTextInput') as Component,
}

const fallback = resolveComponent('WidgetsTextInput') as Component

const widgetComponent = computed(() => {
  const widget = props.schema['x-widget'] ?? 'text'
  return widgetMap[widget] ?? fallback
})
</script>

<template>
  <component
    :is="widgetComponent"
    :model-value="modelValue"
    :label="label"
    :description="description"
    :required="required"
    :disabled="isDisabled"
    :schema="schema"
    @update:model-value="emit('update:modelValue', $event)"
  />
</template>

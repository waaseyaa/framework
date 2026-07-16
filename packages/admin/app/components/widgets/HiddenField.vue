<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

defineProps<{
  modelValue: any
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
</script>

<template>
  <!-- Hidden fields render nothing in the UI -->
</template>

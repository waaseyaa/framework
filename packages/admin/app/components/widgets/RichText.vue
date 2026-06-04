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

const allowedTags = new Set([
  'P', 'BR', 'B', 'I', 'U', 'STRONG', 'EM', 'A',
  'UL', 'OL', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
  'BLOCKQUOTE', 'PRE', 'CODE', 'SUB', 'SUP', 'HR',
])

function sanitizeHtml(html: string): string {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html, 'text/html')
  function walk(node: Node): string {
    if (node.nodeType === Node.TEXT_NODE) return node.textContent ?? ''
    if (node.nodeType !== Node.ELEMENT_NODE) return ''
    const el = node as Element
    if (!allowedTags.has(el.tagName)) {
      return Array.from(el.childNodes).map(walk).join('')
    }
    const tag = el.tagName.toLowerCase()
    const children = Array.from(el.childNodes).map(walk).join('')
    if (tag === 'a') {
      const href = el.getAttribute('href') ?? ''
      if (href.startsWith('http://') || href.startsWith('https://') || href.startsWith('/')) {
        return `<${tag} href="${href}">${children}</${tag}>`
      }
      return children
    }
    if (tag === 'br' || tag === 'hr') return `<${tag}>`
    return `<${tag}>${children}</${tag}>`
  }
  return Array.from(doc.body.childNodes).map(walk).join('')
}

// The contenteditable DOM is driven imperatively, NOT via a reactive `v-html`
// binding. Binding `v-html` to a contenteditable re-renders it on every
// keystroke (each input updates modelValue, which re-renders the element),
// resetting the caret to the start and scrambling typed text. Instead we set
// innerHTML once on mount and only when the value changes from OUTSIDE this
// component, skipping the echo of the user's own edits so the caret is kept.
const editorRef = ref<HTMLDivElement | null>(null)
let echoOwnInput = false

onMounted(() => {
  if (editorRef.value) {
    editorRef.value.innerHTML = sanitizeHtml(props.modelValue)
  }
})

watch(
  () => props.modelValue,
  (value) => {
    // Skip the reactive echo of our own @input emit so we never clobber the
    // caret while the user is typing.
    if (echoOwnInput) {
      echoOwnInput = false
      return
    }
    const el = editorRef.value
    if (!el) {
      return
    }
    const next = sanitizeHtml(value)
    if (el.innerHTML !== next) {
      el.innerHTML = next
    }
  },
)

function onInput(event: Event) {
  const el = event.target as HTMLDivElement
  echoOwnInput = true
  emit('update:modelValue', el.innerHTML)
}
</script>

<template>
  <div class="field">
    <label v-if="label" class="field-label">
      {{ label }}
      <span v-if="required" class="required">*</span>
    </label>
    <div
      ref="editorRef"
      contenteditable
      class="field-input field-richtext"
      :class="{ disabled }"
      @input="onInput"
    />
    <p v-if="description" class="field-description">{{ description }}</p>
  </div>
</template>

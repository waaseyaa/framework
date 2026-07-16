<script setup lang="ts">
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

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const generatedId = useId()
const inputId = computed(() => props.inputId || props.id || `richtext-${generatedId.replaceAll(':', '')}`)
const labelId = computed(() => `${inputId.value}-label`)
const resolvedDescriptionId = computed(() => props.descriptionId || `${inputId.value}-description`)
const resolvedErrorId = computed(() => props.errorId || `${inputId.value}-error`)
const resolvedDescribedBy = computed(() => {
  if (props.describedBy) return props.describedBy
  const ids = []
  if (props.description) ids.push(resolvedDescriptionId.value)
  if (props.error) ids.push(resolvedErrorId.value)
  return ids.length ? ids.join(' ') : undefined
})

const editorRef = ref<HTMLDivElement | null>(null)
const sourceRef = ref<HTMLTextAreaElement | null>(null)
const sourceMode = ref(false)
const canonicalHtml = ref(props.modelValue ?? '')
let ownEmission: string | undefined

/**
 * Build an inert visual editing projection without changing canonicalHtml.
 * Active/embed content remains available byte-for-byte in source mode, but it
 * is represented by a placeholder here so opening a record cannot execute a
 * script, submit a form, or fetch a remote image/embed.
 */
function safeVisualProjection(html: string): string {
  if (!html) return ''

  // Template contents are inert: unlike a live preview (and unlike some
  // DOMParser implementations), parsing here cannot execute scripts or start
  // image/embed fetches before we replace active elements.
  const template = document.createElement('template')
  template.innerHTML = html
  const removeEntirely = new Set([
    'SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT',
    // Document-level navigation/fetch primitives and namespaced active
    // subtrees must never move from the inert template into the live editor.
    'BASE', 'LINK', 'META', 'FRAME', 'FRAMESET', 'SVG', 'MATH',
  ])
  const replaceWithPlaceholder = new Set([
    'IFRAME', 'EMBED', 'OBJECT', 'IMG', 'VIDEO', 'AUDIO', 'SOURCE', 'TRACK',
    'FORM', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA',
  ])

  for (const element of Array.from(template.content.querySelectorAll('*'))) {
    const tagName = element.tagName.toUpperCase()
    if (removeEntirely.has(tagName)) {
      element.remove()
      continue
    }

    if (replaceWithPlaceholder.has(tagName)) {
      const placeholder = document.createElement('span')
      placeholder.className = 'richtext-inert-placeholder'
      placeholder.dataset.element = tagName.toLowerCase()
      const alt = element.getAttribute('alt') || element.getAttribute('title')
      placeholder.textContent = alt || `[${tagName.toLowerCase()} — edit in HTML source]`
      element.replaceWith(placeholder)
      continue
    }

    for (const attribute of Array.from(element.attributes)) {
      const name = attribute.name.toLowerCase()
      if (
        name.startsWith('on')
        || name === 'style'
        || name === 'src'
        || name === 'srcset'
        || name === 'poster'
        || name === 'ping'
        || name === 'background'
        || name === 'action'
        || name === 'formaction'
        || name === 'xlink:href'
      ) {
        element.removeAttribute(attribute.name)
      }
    }

    if (tagName === 'A') {
      const href = element.getAttribute('href')?.trim() ?? ''
      if (
        href
        && !href.startsWith('/')
        && !href.startsWith('#')
        && !/^(https?:|mailto:|tel:)/i.test(href)
      ) {
        element.removeAttribute('href')
      }
    }
  }

  return template.innerHTML
}

function renderVisualProjection() {
  const editor = editorRef.value
  if (!editor) return
  const projection = safeVisualProjection(canonicalHtml.value)
  if (editor.innerHTML !== projection) editor.innerHTML = projection
}

onMounted(renderVisualProjection)

watch(
  () => props.modelValue,
  async (value) => {
    const next = value ?? ''
    if (ownEmission === next) {
      ownEmission = undefined
      return
    }
    ownEmission = undefined
    canonicalHtml.value = next
    if (!sourceMode.value) {
      await nextTick()
      renderVisualProjection()
    }
  },
)

function updateValue(value: string) {
  canonicalHtml.value = value
  ownEmission = value
  emit('update:modelValue', value)
}

function onVisualInput(event: Event) {
  const editor = event.currentTarget as HTMLDivElement
  const safeHtml = safeVisualProjection(editor.innerHTML)
  if (safeHtml !== editor.innerHTML) editor.innerHTML = safeHtml
  updateValue(safeHtml)
}

function onSourceInput(event: Event) {
  updateValue((event.currentTarget as HTMLTextAreaElement).value)
}

function preventLinkNavigation(event: MouseEvent) {
  if ((event.target as Element).closest('a')) event.preventDefault()
}

async function toggleSourceMode() {
  if (props.disabled) return
  sourceMode.value = !sourceMode.value
  await nextTick()
  if (sourceMode.value) {
    sourceRef.value?.focus()
  }
  else {
    renderVisualProjection()
    editorRef.value?.focus()
  }
}

function onEditorKeydown(event: KeyboardEvent) {
  if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 's') {
    event.preventDefault()
    void toggleSourceMode()
  }
}
</script>

<template>
  <div class="field richtext-field">
    <label
      v-if="label"
      :id="labelId"
      class="field-label"
      :for="inputId"
    >
      {{ label }}
      <template v-if="required">
        <span class="required" aria-hidden="true">*</span>
        <span class="visually-hidden"> (required)</span>
      </template>
    </label>

    <div class="richtext-toolbar" role="toolbar" aria-label="Rich text editing tools">
      <button
        type="button"
        class="richtext-source-toggle touch-target"
        :aria-controls="inputId"
        :aria-pressed="sourceMode"
        :disabled="disabled"
        @click="toggleSourceMode"
      >
        {{ sourceMode ? 'Return to visual editor' : 'Edit HTML source' }}
      </button>
    </div>

    <textarea
      v-if="sourceMode"
      :id="inputId"
      ref="sourceRef"
      class="field-input field-source touch-target"
      :value="canonicalHtml"
      :disabled="disabled"
      :aria-labelledby="label ? labelId : undefined"
      :aria-label="label ? undefined : 'Rich text HTML source'"
      :aria-describedby="resolvedDescribedBy"
      :aria-required="required ? 'true' : undefined"
      :aria-invalid="error ? 'true' : undefined"
      spellcheck="false"
      @input="onSourceInput"
      @keydown="onEditorKeydown"
    />
    <div
      v-else
      :id="inputId"
      ref="editorRef"
      role="textbox"
      aria-multiline="true"
      :aria-labelledby="label ? labelId : undefined"
      :aria-label="label ? undefined : 'Rich text'"
      :aria-describedby="resolvedDescribedBy"
      :aria-required="required ? 'true' : undefined"
      :aria-invalid="error ? 'true' : undefined"
      :aria-disabled="disabled ? 'true' : undefined"
      :contenteditable="disabled ? 'false' : 'true'"
      class="field-input field-richtext touch-target"
      :class="{ disabled }"
      @click="preventLinkNavigation"
      @input="onVisualInput"
      @keydown="onEditorKeydown"
    />

    <p v-if="description" :id="resolvedDescriptionId" class="field-description">
      {{ description }}
    </p>
    <p v-if="error" :id="resolvedErrorId" class="field-error" role="alert">
      <span aria-hidden="true">!</span>
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.richtext-toolbar {
  display: flex;
  margin-block-end: 0.375rem;
}

.richtext-source-toggle {
  border: 1px solid currentColor;
  border-radius: 0.25rem;
  background: transparent;
  color: inherit;
  padding: 0.375rem 0.625rem;
  font: inherit;
  cursor: pointer;
}

.richtext-source-toggle:disabled {
  cursor: not-allowed;
}

.field-richtext {
  min-block-size: 8rem;
  overflow-wrap: anywhere;
}

.field-source {
  min-block-size: 12rem;
  resize: vertical;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
}

.field-richtext:focus-visible,
.field-source:focus-visible,
.richtext-source-toggle:focus-visible {
  outline: 3px solid #1d4ed8;
  outline-offset: 2px;
}

.field-richtext[aria-disabled="true"] {
  cursor: not-allowed;
}

:deep(.richtext-inert-placeholder) {
  display: inline-block;
  border: 1px dashed currentColor;
  padding: 0.125rem 0.25rem;
}

.field-error {
  font-weight: 600;
}

.visually-hidden {
  position: absolute;
  inline-size: 1px;
  block-size: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>

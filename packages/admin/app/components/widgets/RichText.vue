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
const projectionOmitsMarkup = ref(false)
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
  const original = document.createElement('template')
  original.innerHTML = canonicalHtml.value
  projectionOmitsMarkup.value = projection !== original.innerHTML
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
    resetLink()
    savedRange = null
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
  resetLink()
  const editor = event.currentTarget as HTMLDivElement
  const safeHtml = safeVisualProjection(editor.innerHTML)
  if (safeHtml !== editor.innerHTML) editor.innerHTML = safeHtml
  projectionOmitsMarkup.value = false
  updateValue(safeHtml)
}

function onSourceInput(event: Event) {
  updateValue((event.currentTarget as HTMLTextAreaElement).value)
}

const formats = [
  { label: 'Paragraph', command: 'formatBlock', value: 'p' },
  { label: 'Heading', command: 'formatBlock', value: 'h2' },
  { label: 'Bold', command: 'bold' },
  { label: 'Italic', command: 'italic' },
  { label: 'Bulleted list', command: 'insertUnorderedList' },
  { label: 'Numbered list', command: 'insertOrderedList' },
]

const activeTool = ref('Paragraph')
const pressedTools = ref<string[]>([])
function updatePressedTools() {
  if (typeof document.queryCommandState !== 'function') return
  pressedTools.value = formats.filter(item => item.value
    ? document.queryCommandValue('formatBlock').toLowerCase() === item.value
    : document.queryCommandState(item.command)).map(item => item.label)
}

function moveToolbarFocus(event: KeyboardEvent) {
  const buttons = Array.from((event.currentTarget as HTMLElement).querySelectorAll('button:not(:disabled)'))
  const index = buttons.indexOf(event.target as HTMLButtonElement)
  if (index < 0) return
  const names = ['Home', 'End', 'ArrowRight', 'ArrowLeft']
  if (!names.includes(event.key)) return
  event.preventDefault()
  const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1
    : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length
  ;(buttons[next] as HTMLButtonElement).focus()
}

let savedRange: Range | null = null
let linkRange: Range | null = null
const editingLink = ref<HTMLAnchorElement | null>(null)
const linkOpen = ref(false)
const linkAddress = ref('')
const linkError = ref('')
const addressInput = ref<HTMLInputElement | null>(null)

watch(() => props.disabled, (disabled) => {
  if (disabled) {
    resetLink()
    savedRange = null
  }
})

function rememberSelection() {
  const selection = window.getSelection()
  if (!selection?.rangeCount) return
  const range = selection.getRangeAt(0)
  if (editorRef.value?.contains(range.commonAncestorContainer)) {
    savedRange = range.cloneRange()
    updatePressedTools()
  }
}

function restoreSelection(range = savedRange) {
  const editor = editorRef.value
  const selection = window.getSelection()
  if (!editor || props.disabled || sourceMode.value || !selection) return false
  editor.focus()
  if (!range || !editor.contains(range.commonAncestorContainer)) {
    range = document.createRange()
    range.selectNodeContents(editor)
    range.collapse(false)
  }
  selection.removeAllRanges()
  selection.addRange(range)
  return true
}

function format(command: string, value?: string) {
  if (linkOpen.value || !restoreSelection()) return
  runEditCommand(command, value)
}

function runEditCommand(command: string, value?: string) {
  // Native editing commands participate in the contenteditable undo history.
  document.execCommand(command, false, value)
  rememberSelection()
}

async function openLink(anchor?: HTMLAnchorElement) {
  const editor = editorRef.value
  if (!editor || props.disabled || sourceMode.value) return
  if (linkOpen.value) {
    addressInput.value?.focus()
    return
  }
  rememberSelection()
  linkRange = savedRange && editor.contains(savedRange.commonAncestorContainer)
    ? savedRange.cloneRange()
    : document.createRange()
  if (!editor.contains(linkRange.commonAncestorContainer)) {
    linkRange.selectNodeContents(editor)
    linkRange.collapse(false)
  }
  const node = linkRange.commonAncestorContainer
  const element = node.nodeType === Node.ELEMENT_NODE ? node as Element : node.parentElement
  const link = anchor ?? element?.closest('a')
  editingLink.value = link && editor.contains(link) ? link : null
  if (editingLink.value) {
    linkRange.selectNodeContents(editingLink.value)
  }
  linkAddress.value = editingLink.value?.getAttribute('href') ?? ''
  linkError.value = ''
  linkOpen.value = true
  await nextTick()
  addressInput.value?.focus()
}

function resetLink() {
  linkOpen.value = false
  editingLink.value = null
  linkRange = null
}

function closeLink() {
  resetLink()
  restoreSelection()
}

function removeLink() {
  const range = linkRange
  if (!range || !editingLink.value || !restoreSelection(range)) return
  resetLink()
  runEditCommand('unlink')
}

function applyLink() {
  const href = linkAddress.value.trim()
  let valid = false
  try {
    const url = new URL(href)
    valid = /^(https?:\/\/|mailto:|tel:)/i.test(href)
      && /^(https?:|mailto:|tel:)$/.test(url.protocol)
      && !/[\s\\]/.test(href)
  } catch { /* Report invalid input in the link editor. */ }
  if (!valid) {
    linkError.value = 'Enter a full web address starting with https:// or http://, an email link starting with mailto:, or a telephone link starting with tel:.'
    addressInput.value?.focus()
    return
  }
  const range = linkRange
  if (!range || !restoreSelection(range)) return
  resetLink()
  if (range.collapsed) {
    // A bare insertion uses the address as its visible label. DOM serialization
    // escapes both the address and label before the native undoable insertion.
    const link = document.createElement('a')
    link.setAttribute('href', href)
    link.textContent = href
    runEditCommand('insertHTML', link.outerHTML)
  } else {
    runEditCommand('createLink', href)
  }
}

function onEditorClick(event: MouseEvent) {
  const anchor = (event.target as Element).closest('a')
  if (anchor) {
    event.preventDefault()
    void openLink(anchor)
  }
}

async function toggleSourceMode() {
  if (props.disabled) return
  resetLink()
  savedRange = null
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
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !sourceMode.value) {
    event.preventDefault()
    void openLink()
    return
  }
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

    <div class="richtext-toolbar" role="toolbar" aria-label="Rich text editing tools" @keydown="moveToolbarFocus">
      <template v-if="!sourceMode">
        <button
          v-for="item in formats" :key="item.label" type="button" class="touch-target"
          :aria-controls="inputId" :disabled="disabled || linkOpen" :aria-pressed="pressedTools.includes(item.label)"
          :tabindex="activeTool === item.label ? 0 : -1" @focus="activeTool = item.label"
          @mousedown.prevent @click="format(item.command, item.value)"
        >{{ item.label }}</button>
        <button
type="button" class="touch-target" :disabled="disabled" :aria-expanded="linkOpen" :tabindex="activeTool === 'Link' ? 0 : -1" :aria-controls="inputId + '-link'"
          @focus="activeTool = 'Link'" @mousedown.prevent @click="openLink()">Link</button>
      </template>
      <button
        type="button"
        class="richtext-source-toggle touch-target"
        :tabindex="sourceMode || activeTool === 'source' ? 0 : -1" :aria-controls="inputId"
        :aria-pressed="sourceMode"
        :disabled="disabled"
        @focus="activeTool = 'source'"
        @click="toggleSourceMode"
      >
        {{ sourceMode ? 'Return to visual editor' : 'Edit HTML source' }}
      </button>
    </div>

    <div
v-if="linkOpen && !sourceMode && !disabled" :id="inputId + '-link'" class="link-editor"
      role="group" aria-label="Edit link" @keydown.esc.prevent.stop="closeLink">
      <label :for="inputId + '-address'">Link address</label>
      <input
:id="inputId + '-address'" ref="addressInput" v-model="linkAddress" type="text"
        :aria-invalid="!!linkError" :aria-describedby="linkError ? inputId + '-link-error' : undefined"
        @keydown.enter.prevent.stop="applyLink">
      <button type="button" class="touch-target" @click="applyLink">Apply</button>
      <button v-if="editingLink" type="button" class="touch-target" @click="removeLink">Remove link</button>
      <button type="button" class="touch-target" @click="closeLink">Cancel</button>
      <span v-if="linkError" :id="inputId + '-link-error'" role="alert">{{ linkError }}</span>
    </div>

    <p v-if="!sourceMode && !disabled && projectionOmitsMarkup" class="field-description">
      This field contains markup hidden in the visual editor. Use Edit HTML source to preserve it when editing.
    </p>
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
      @click="onEditorClick"
      @mouseup="rememberSelection"
      @keyup="rememberSelection"
      @blur="rememberSelection"
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
.richtext-toolbar, .link-editor {
  flex-wrap: wrap;
  gap: 0.25rem;
  display: flex;
  margin-block-end: 0.375rem;
}

.richtext-toolbar button, .link-editor button {
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

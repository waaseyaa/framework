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
const inputId = props.inputId ?? useId()

const uploading = ref(false)
const progress = ref(0)
const errorMessage = ref('')
const previewUrl = ref('')
const constraintsLoading = ref(false)
const maxBytes = ref<number | null>(null)
const allowedMimeTypes = ref<string[]>([])

const selectedBundle = computed(() => context?.selectedBundle.value ?? null)
const acceptedTypes = computed(() => allowedMimeTypes.value.join(','))
const constraintsText = computed(() => {
  const parts: string[] = []
  if (allowedMimeTypes.value.length > 0) parts.push(`Accepted types: ${allowedMimeTypes.value.join(', ')}`)
  if (maxBytes.value !== null) parts.push(`Maximum size: ${formatBytes(maxBytes.value)}`)
  return parts.join('. ')
})
const accessibleDescription = computed(() => [
  props.describedBy,
  constraintsText.value ? `${inputId}-constraints` : undefined,
  errorMessage.value ? `${inputId}-upload-error` : undefined,
].filter(Boolean).join(' ') || undefined)

let objectUrl: string | null = null

function setPreviewFromFile(file: globalThis.File) {
  if (!file.type.startsWith('image/')) {
    clearPreview()
    return
  }

  if (objectUrl !== null) {
    URL.revokeObjectURL(objectUrl)
    objectUrl = null
  }

  objectUrl = URL.createObjectURL(file)
  previewUrl.value = objectUrl
}

function clearPreview() {
  if (objectUrl !== null) {
    URL.revokeObjectURL(objectUrl)
    objectUrl = null
  }
  previewUrl.value = ''
}

onBeforeUnmount(() => {
  clearPreview()
})

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} bytes`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${Math.round((bytes / (1024 * 1024)) * 10) / 10} MB`
}

function publicErrorForStatus(status: number): string {
  if (status === 401) return 'Authentication is required before uploading.'
  if (status === 403) return 'You are not permitted to upload this media.'
  if (status === 404) return 'The media upload service is unavailable.'
  if (status === 400 || status === 415 || status === 422) return 'The file was not accepted by upload validation.'
  if (status >= 500) return 'The upload server failed. Please try again.'
  return 'The upload could not be completed.'
}

async function loadConstraints() {
  constraintsLoading.value = true
  try {
    const response = await fetch('/api/media/upload', {
      method: 'GET',
      headers: { Accept: 'application/vnd.api+json' },
      credentials: 'include',
    })
    if (!response.ok) {
      errorMessage.value = publicErrorForStatus(response.status)
      return
    }
    const contentType = response.headers.get('Content-Type')?.toLowerCase() ?? ''
    if (!contentType.includes('json')) {
      errorMessage.value = 'Upload constraints returned an invalid response.'
      return
    }
    const payload = await response.json()
    const constraints = payload?.meta?.constraints
    const size = constraints?.max_bytes
    const types = constraints?.allowed_mime_types
    if (!Number.isSafeInteger(size) || size <= 0 || !Array.isArray(types) || !types.every((type: unknown) => typeof type === 'string' && type !== '')) {
      errorMessage.value = 'Upload constraints returned an invalid response.'
      return
    }
    maxBytes.value = size
    allowedMimeTypes.value = types
  } catch {
    errorMessage.value = 'Upload constraints could not be loaded because of a network failure.'
  } finally {
    constraintsLoading.value = false
  }
}

onMounted(loadConstraints)

function xsrfToken(): string | null {
  if (typeof document === 'undefined') return null
  const item = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))
  return item ? item.slice('XSRF-TOKEN='.length) : null
}

function mimeAllowed(mimeType: string): boolean {
  return allowedMimeTypes.value.some((allowed) => {
    if (allowed === mimeType) return true
    if (!allowed.endsWith('/*')) return false
    return mimeType.startsWith(allowed.slice(0, -1))
  })
}

function uploadFile(file: globalThis.File) {
  if (uploading.value) return
  const bundle = selectedBundle.value
  if (!bundle) {
    errorMessage.value = 'Select a media bundle before uploading a file.'
    return
  }
  if (allowedMimeTypes.value.length > 0 && !mimeAllowed(file.type)) {
    errorMessage.value = 'This file type is not supported.'
    return
  }
  if (maxBytes.value !== null && file.size > maxBytes.value) {
    errorMessage.value = 'This file is too large.'
    return
  }

  const formData = new FormData()
  formData.append('file', file)
  formData.append('name', file.name)
  formData.append('bundle', bundle)

  uploading.value = true
  progress.value = 0
  errorMessage.value = ''

  const xhr = new XMLHttpRequest()
  xhr.open('POST', '/api/media/upload', true)
  xhr.setRequestHeader('Accept', 'application/vnd.api+json')
  const token = xsrfToken()
  if (token) xhr.setRequestHeader('X-XSRF-TOKEN', token)
  xhr.withCredentials = true

  xhr.upload.addEventListener('progress', (event) => {
    if (!event.lengthComputable || event.total <= 0) {
      return
    }
    progress.value = Math.round((event.loaded / event.total) * 100)
  })

  xhr.addEventListener('load', () => {
    uploading.value = false

    if (xhr.status < 200 || xhr.status >= 300) {
      errorMessage.value = publicErrorForStatus(xhr.status)
      return
    }

    try {
      const payload = JSON.parse(xhr.responseText ?? '{}')
      const attributes = payload?.data?.attributes ?? {}
      const value = attributes.uri
      if (typeof value !== 'string' || !value.startsWith('public://')) {
        errorMessage.value = 'Upload returned an invalid response.'
        return
      }
      emit('update:modelValue', value)
    } catch {
      errorMessage.value = 'Upload returned an invalid response.'
    }
  })

  xhr.addEventListener('error', () => {
    uploading.value = false
    errorMessage.value = 'The upload failed because of a network connection problem.'
  })

  xhr.send(formData)
}

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) {
    return
  }

  setPreviewFromFile(file)
  uploadFile(file)
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
      type="file"
      class="field-input"
      :accept="acceptedTypes || undefined"
      :required="required"
      :aria-required="required ? 'true' : undefined"
      :aria-invalid="error || errorMessage ? 'true' : undefined"
      :aria-describedby="accessibleDescription"
      :disabled="disabled || uploading || constraintsLoading"
      @change="onFileChange"
    >

    <p
      v-if="constraintsText"
      :id="`${inputId}-constraints`"
      class="field-description"
    >{{ constraintsText }}</p>

    <div v-if="uploading" class="field-upload-progress">
      <progress :value="progress" max="100" />
      <span>{{ progress }}%</span>
    </div>

    <img
      v-if="previewUrl"
      :src="previewUrl"
      alt="Image preview"
      class="field-upload-preview"
    >

    <p v-if="modelValue" class="field-description">A file has been uploaded.</p>

    <p v-if="description" :id="descriptionId" class="field-description">{{ description }}</p>
    <p v-if="error" :id="errorId" class="field-error"><strong>Error:</strong> {{ error }}</p>
    <p v-if="errorMessage" :id="`${inputId}-upload-error`" class="field-error" role="alert" aria-live="assertive"><strong>Error:</strong> {{ errorMessage }}</p>
  </div>
</template>

<style scoped>
.field-upload-progress {
  margin-top: 0.5rem;
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.field-upload-preview {
  margin-top: 0.75rem;
  max-width: 240px;
  max-height: 160px;
  object-fit: cover;
  border: 1px solid #d1d5db;
  border-radius: 0.25rem;
}

.field-error {
  margin-top: 0.5rem;
  color: #b91c1c;
  font-size: 0.875rem;
}
</style>

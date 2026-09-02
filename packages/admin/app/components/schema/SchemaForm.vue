<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import type { SchemaProperty } from '~/composables/useSchema'
import type { FormSection } from '~/contracts/schema'
import { useEntity } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'
import { schemaFormContextKey } from './schemaFormContext'
import { normalizeValidationFailure } from './validationErrors'
import type { SaveAdvisory } from '~/contracts/transport'
import { classifyEmbedFailure, type EmbedFailure } from '~/runtime/embedLifecycle'

const props = defineProps<{
  entityType: string
  entityId?: string
  initialBundle?: string
  initialValues?: Record<string, any>
}>()

const emit = defineEmits<{
  saved: [resource: any]
  error: [message: string]
  ready: []
  dirty: [dirty: boolean]
  failure: [failure: EmbedFailure]
}>()

const { t } = useLanguage()
const { schema, error: schemaError, failure: schemaFailure, fetch: fetchSchema, sortedProperties } = useSchema(props.entityType)
const { get, create, update } = useEntity()

const formData = ref<Record<string, any>>({})
const saving = ref(false)
let submitInFlight = false
const loadError = ref<string | null>(null)
const loadFailure = ref<EmbedFailure | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const globalErrors = ref<string[]>([])
const validationSummary = ref<HTMLElement | null>(null)
const advisoryReview = ref<HTMLElement | null>(null)
const saveAdvisories = ref<SaveAdvisory[]>([])
const pendingAdvisorySubmission = ref<{
  values: Record<string, any>
  acknowledgements: string[]
} | null>(null)
const isEditMode = computed(() => !!props.entityId)
const bundleKey = computed(() => schema.value?.['x-bundle-key'] ?? null)
const selectedBundle = computed(() => {
  const key = bundleKey.value
  const value = key ? formData.value[key] : null
  return typeof value === 'string' && value !== '' ? value : null
})
// True for the whole initial load (schema, plus the existing record in edit
// mode). Keeps the form in one busy state instead of flashing blank between
// the schema and entity fetches.
const loading = ref(true)

provide(schemaFormContextKey, {
  formData,
  isEditMode,
  entityType: props.entityType,
  bundleKey,
  selectedBundle,
})

function valuesForSchema(
  targetSchema: NonNullable<typeof schema.value>,
  existing: Record<string, any> = {},
): Record<string, any> {
  const values: Record<string, any> = {}
  for (const [fieldName, fieldSchema] of Object.entries(targetSchema.properties ?? {})) {
    if (fieldName in existing) {
      values[fieldName] = existing[fieldName]
    } else if ('default' in fieldSchema) {
      values[fieldName] = fieldSchema.default
    } else if (fieldSchema.type === 'boolean') {
      values[fieldName] = false
    }
  }
  return values
}

// Load schema, then optionally load existing entity if schema succeeded.
// In edit mode the entity id scopes the schema to the record's bundle so its
// per-bundle fields (e.g. a page's body) appear in the form.
onMounted(async () => {
  clearValidation()
  if (props.entityId) {
    // Edit mode: the bundle-scoped schema and the existing record are
    // independent reads — fetch them concurrently so the slower one bounds the
    // wait. The entity GET is deduped against the sibling history widget
    // requesting the same record in the same tick (see the transport adapter).
    const [, entityResult] = await Promise.allSettled([
      fetchSchema({ id: props.entityId }),
      get(props.entityType, props.entityId),
    ])
    if (entityResult.status === 'fulfilled') {
      formData.value = { ...entityResult.value.attributes }
    } else {
      const e: any = entityResult.reason
      loadError.value = e?.data?.errors?.[0]?.detail ?? e?.message ?? t('error_loading_entity')
      loadFailure.value = classifyEmbedFailure(e)
    }
  } else {
    // Create mode: load the generic schema first so its bundle key remains the
    // authority, then resolve an explicitly requested bundle before rendering.
    // This lets role-focused shells open the exact shared editor for a known
    // content type without duplicating any field/widget implementation.
    await fetchSchema()
    const requestedBundleKey = schema.value?.['x-bundle-key']
    const candidateBundle = props.initialBundle ?? (requestedBundleKey
      ? props.initialValues?.[requestedBundleKey]
      : undefined)
    // A bundle-scoped create link (#2418) can go stale or be hand-edited. When
    // the base schema advertises its registered bundles, a value outside that
    // set is dropped here rather than sent: requesting a scope the server will
    // refuse turns a stale link into an error page, where the contract is to
    // degrade to the unscoped create form. Schemas that advertise no enum keep
    // the previous behaviour, since there is nothing to check the value against.
    const advertisedBundles = requestedBundleKey
      ? schema.value?.properties?.[requestedBundleKey]?.enum
      : undefined
    const requestedBundle = advertisedBundles && !advertisedBundles.includes(candidateBundle)
      ? undefined
      : candidateBundle
    if (typeof requestedBundle === 'string' && requestedBundle !== '') {
      await fetchSchema({ bundle: requestedBundle })
    }
    if (schema.value) {
      formData.value = valuesForSchema(schema.value, {
        ...(props.initialValues ?? {}),
        ...(requestedBundleKey && requestedBundle
          ? { [requestedBundleKey]: requestedBundle }
          : {}),
      })
    }
  }
  loading.value = false
  if (schemaFailure.value) emit('failure', schemaFailure.value)
  else if (loadFailure.value) emit('failure', loadFailure.value)
  else if (loadError.value === null && schema.value !== null) emit('ready')
})

const editableFields = computed(() => sortedProperties(true))
type EditableField = [string, SchemaProperty]
type RenderedSection = Omit<FormSection, 'fields'> & { fields: EditableField[] }

const fieldSections = computed<RenderedSection[]>(() => {
  const fields = editableFields.value as EditableField[]
  const declared = schema.value?.['x-form-sections']
  if (!Array.isArray(declared) || declared.length === 0) {
    return [{ id: 'all', label: '', fields }]
  }

  const byName = new Map(fields)
  const assigned = new Set<string>()
  const sections: RenderedSection[] = []
  for (const candidate of declared) {
    if (!candidate || typeof candidate !== 'object') continue
    if (typeof candidate.id !== 'string' || candidate.id.trim() === '') continue
    if (typeof candidate.label !== 'string' || candidate.label.trim() === '') continue
    if (!Array.isArray(candidate.fields)) continue

    const sectionFields: EditableField[] = []
    for (const fieldName of candidate.fields) {
      if (typeof fieldName !== 'string' || assigned.has(fieldName)) continue
      const fieldSchema = byName.get(fieldName)
      if (!fieldSchema) continue
      assigned.add(fieldName)
      sectionFields.push([fieldName, fieldSchema])
    }
    if (sectionFields.length === 0) continue

    sections.push({
      id: candidate.id,
      label: candidate.label,
      description: typeof candidate.description === 'string' ? candidate.description : undefined,
      collapsible: candidate.collapsible === true,
      collapsed: candidate.collapsible === true && candidate.collapsed === true,
      fields: sectionFields,
    })
  }

  const unassigned = fields.filter(([fieldName]) => !assigned.has(fieldName))
  if (unassigned.length > 0) {
    sections.push({ id: 'other', label: t('form_other_details'), fields: unassigned })
  }

  return sections.length > 0 ? sections : [{ id: 'all', label: '', fields }]
})

const sectionOpenState = ref<Record<string, boolean>>({})

watch(fieldSections, (sections) => {
  const next: Record<string, boolean> = {}
  for (const section of sections) {
    if (!section.collapsible) continue
    next[section.id] = sectionOpenState.value[section.id] ?? !section.collapsed
  }
  sectionOpenState.value = next
}, { immediate: true })

watch(fieldErrors, () => {
  const next = { ...sectionOpenState.value }
  for (const section of fieldSections.value) {
    if (section.collapsible && section.fields.some(([fieldName]) => fieldErrors.value[fieldName] !== undefined)) {
      next[section.id] = true
    }
  }
  sectionOpenState.value = next
}, { deep: true })

function sectionIsOpen(section: RenderedSection): boolean {
  return !section.collapsible || sectionOpenState.value[section.id] !== false
}

function onSectionToggle(section: RenderedSection, event: Event): void {
  if (!section.collapsible) return
  const details = event.currentTarget
  if (!(details instanceof HTMLDetailsElement)) return
  sectionOpenState.value = { ...sectionOpenState.value, [section.id]: details.open }
}

const validationMessages = computed(() => [
  ...Object.entries(fieldErrors.value).map(([fieldName, message]) => ({ fieldName, message })),
  ...globalErrors.value.map(message => ({ fieldName: null, message })),
])

function fieldInputId(fieldName: string): string {
  const normalize = (value: string) => value.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '')
  // Keep the readable portion for diagnostics, then append a reversible
  // code-point token. Slugging alone aliases distinct schema keys such as
  // `foo_bar` and `foo-bar`, breaking every label/help/error association.
  const uniqueToken = [...fieldName].map(character => character.codePointAt(0)!.toString(16)).join('-')
  return `schema-${normalize(props.entityType)}-${normalize(fieldName)}-${uniqueToken}`
}

function fieldIsRequired(fieldName: string, fieldSchema: SchemaProperty): boolean {
  return fieldSchema['x-required'] === true || schema.value?.required?.includes(fieldName) === true
}

function clearValidation(fieldName?: string) {
  if (fieldName !== undefined) {
    if (fieldErrors.value[fieldName] !== undefined) {
      fieldErrors.value = Object.fromEntries(
        Object.entries(fieldErrors.value).filter(([name]) => name !== fieldName),
      )
    }
    if (Object.keys(fieldErrors.value).length === 0) globalErrors.value = []
    return
  }
  fieldErrors.value = {}
  globalErrors.value = []
}

function clearAdvisoryReview() {
  saveAdvisories.value = []
  pendingAdvisorySubmission.value = null
}

function advisoriesFromFailure(error: unknown): SaveAdvisory[] | null {
  if (!error || typeof error !== 'object') return null
  const failure = error as Record<string, unknown>
  if (failure.status !== 428
    || failure.code !== 'SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED') {
    return null
  }
  const meta = failure.meta
  if (!meta || typeof meta !== 'object') return null
  const candidates = (meta as Record<string, unknown>).save_advisories
  if (!Array.isArray(candidates) || candidates.length === 0 || candidates.length > 32) return null

  const advisories: SaveAdvisory[] = []
  for (const candidate of candidates) {
    if (!candidate || typeof candidate !== 'object') return null
    const value = candidate as Record<string, unknown>
    if (typeof value.code !== 'string'
      || typeof value.field !== 'string'
      || value.severity !== 'warning'
      || typeof value.message !== 'string'
      || typeof value.acknowledgement !== 'string'
      || !/^[a-f0-9]{64}$/.test(value.acknowledgement)) {
      return null
    }
    advisories.push(value as unknown as SaveAdvisory)
  }

  return advisories
}

function captureJsonCandidate(values: Record<string, any>): Record<string, any> {
  return JSON.parse(JSON.stringify(values)) as Record<string, any>
}

async function focusValidationSummary() {
  await nextTick()
  validationSummary.value?.focus()
}

function validateRequiredFields(): Record<string, string> {
  const errors: Record<string, string> = {}
  for (const [fieldName, fieldSchema] of editableFields.value) {
    if (!fieldIsRequired(fieldName, fieldSchema) || fieldSchema['x-access-restricted']) continue
    const value = formData.value[fieldName]
    if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
      errors[fieldName] = `${fieldSchema['x-label'] ?? fieldName} is required.`
    }
  }
  return errors
}

function isValidIsoDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (match === null) return false
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  if (month < 1 || month > 12 || day < 1) return false
  return day <= new Date(Date.UTC(year, month, 0)).getUTCDate()
}

function validateDateFields(errors: Record<string, string>): Record<string, string> {
  for (const [fieldName, fieldSchema] of editableFields.value) {
    if (errors[fieldName] !== undefined || fieldSchema['x-widget'] !== 'date') continue
    const value = formData.value[fieldName]
    if (value === undefined || value === null || value === '') continue
    const label = fieldSchema['x-label'] ?? fieldName
    if (typeof value !== 'string' || !isValidIsoDate(value)) {
      errors[fieldName] = `${label} must be a valid date in YYYY-MM-DD format.`
    } else if (fieldSchema['x-min'] && value < fieldSchema['x-min']) {
      errors[fieldName] = `${label} must be on or after ${fieldSchema['x-min']}.`
    } else if (fieldSchema['x-max'] && value > fieldSchema['x-max']) {
      errors[fieldName] = `${label} must be on or before ${fieldSchema['x-max']}.`
    }
  }
  return errors
}

async function onFieldUpdate(fieldName: string, value: any, accessRestricted: boolean) {
  if (accessRestricted) return

  formData.value[fieldName] = value
  emit('dirty', true)
  clearAdvisoryReview()
  clearValidation(fieldName)
  const bundleKey = schema.value?.['x-bundle-key']
  if (props.entityId || bundleKey !== fieldName || typeof value !== 'string' || value === '') return

  clearValidation()
  await fetchSchema({ bundle: value })
  if (schemaFailure.value) emit('failure', schemaFailure.value)
  if (schema.value && !schemaError.value) {
    // Preserve shared fields and defaults, but discard values belonging only to
    // the previously selected bundle so they cannot leak into create payloads.
    formData.value = valuesForSchema(schema.value, {
      ...formData.value,
      [bundleKey]: value,
    })
  }
}

async function persistCandidate(
  writableData: Record<string, any>,
  acknowledgements: string[] = [],
) {
  const submittedBundleKey = bundleKey.value
  const submittedBundle = submittedBundleKey ? writableData[submittedBundleKey] : undefined
  try {
    const resource = props.entityId
      ? await update(props.entityType, props.entityId, writableData, acknowledgements)
      : await create(props.entityType, writableData, acknowledgements)
    clearAdvisoryReview()
    emit('dirty', false)
    emit('saved', resource)
  } catch (e: any) {
    // A failed create must retain the bundle that scoped the current schema.
    // This also defends against transport/plugin error handling replacing the
    // reactive payload while the request is in flight.
    if (!props.entityId && submittedBundleKey && typeof submittedBundle === 'string') {
      formData.value[submittedBundleKey] = submittedBundle
    }
    const advisories = advisoriesFromFailure(e)
    if (advisories !== null) {
      saveAdvisories.value = advisories
      pendingAdvisorySubmission.value = {
        values: captureJsonCandidate(writableData),
        acknowledgements: advisories.map(advisory => advisory.acknowledgement),
      }
      await nextTick()
      advisoryReview.value?.focus()
      return
    }
    const normalized = normalizeValidationFailure(e, new Set(editableFields.value.map(([name]) => name)))
    fieldErrors.value = normalized.fieldErrors
    globalErrors.value = normalized.globalErrors
    const msg = normalized.globalErrors[0] ?? Object.values(normalized.fieldErrors)[0] ?? t('error_saving_entity')
    emit('error', msg)
    emit('failure', classifyEmbedFailure(e))
    await focusValidationSummary()
  }
}

async function onSubmit() {
  if (submitInFlight) return
  submitInFlight = true
  clearValidation()
  clearAdvisoryReview()
  const clientErrors = validateDateFields(validateRequiredFields())
  if (Object.keys(clientErrors).length > 0) {
    fieldErrors.value = clientErrors
    await focusValidationSummary()
    submitInFlight = false
    return
  }
  saving.value = true
  try {
    // Entity reads include structural attributes needed for display and
    // identity, but the schema is the write contract. Submit only declared,
    // non-read-only properties; migrated config rows can return structural
    // values (such as bundle/langcode) that their write schema omits.
    const writableData = Object.fromEntries(
      Object.entries(formData.value).filter(([fieldName]) => {
        const property = schema.value?.properties?.[fieldName]
        return property !== undefined && property.readOnly !== true
      }),
    )
    await persistCandidate(writableData)
  } finally {
    saving.value = false
    submitInFlight = false
  }
}

async function confirmAdvisorySubmission() {
  if (submitInFlight || pendingAdvisorySubmission.value === null) return
  submitInFlight = true
  saving.value = true
  const submission = pendingAdvisorySubmission.value
  clearValidation()
  clearAdvisoryReview()
  try {
    await persistCandidate(submission.values, submission.acknowledgements)
  } finally {
    saving.value = false
    submitInFlight = false
  }
}
</script>

<template>
  <div class="schema-form" :aria-busy="loading ? 'true' : 'false'" :data-anchor="`form:${entityType}`">
    <div v-if="loading" class="loading" role="status" aria-live="polite">{{ t('opening') }}</div>
    <div v-else-if="schemaError" class="error">{{ schemaError }}</div>
    <div v-else-if="loadError" class="error">{{ loadError }}</div>
    <form v-else novalidate @submit.prevent="onSubmit">
      <section
        v-if="saveAdvisories.length > 0"
        ref="advisoryReview"
        class="save-advisory-review"
        data-testid="save-advisory-review"
        role="status"
        aria-live="polite"
        tabindex="-1"
      >
        <h2>Review before saving</h2>
        <p>This content can be saved after you acknowledge the following warning.</p>
        <ul>
          <li v-for="advisory in saveAdvisories" :key="advisory.acknowledgement">
            <strong>{{ advisory.field }}</strong>: {{ advisory.message }}
          </li>
        </ul>
        <button type="button" class="btn btn-primary" :disabled="saving" @click="confirmAdvisorySubmission">
          Acknowledge warning and save
        </button>
      </section>
      <div
        v-if="validationMessages.length > 0"
        ref="validationSummary"
        class="validation-summary"
        data-testid="validation-summary"
        role="alert"
        aria-live="assertive"
        tabindex="-1"
      >
        <p class="validation-summary__title">The form could not be saved. Correct the following errors:</p>
        <ul>
          <li v-for="(item, index) in validationMessages" :key="`${item.fieldName ?? 'form'}-${index}`">
            <a v-if="item.fieldName" :href="`#${fieldInputId(item.fieldName)}`">{{ item.message }}</a>
            <span v-else>{{ item.message }}</span>
          </li>
        </ul>
      </div>
      <section
        v-for="section in fieldSections"
        :key="section.id"
        class="form-section"
        data-testid="form-section"
        :data-section-id="section.id"
      >
        <component
          :is="section.collapsible ? 'details' : 'div'"
          class="form-section__panel"
          :open="section.collapsible ? sectionIsOpen(section) : undefined"
          @toggle="onSectionToggle(section, $event)"
        >
          <summary v-if="section.collapsible" class="form-section__summary">
            <span>{{ section.label }}</span>
            <small v-if="section.description">{{ section.description }}</small>
          </summary>
          <template v-else>
            <h2 v-if="section.label">{{ section.label }}</h2>
            <p v-if="section.description" class="form-section__description">{{ section.description }}</p>
          </template>
          <div class="form-section__fields">
            <div
              v-for="[fieldName, fieldSchema] in section.fields"
              :key="fieldName"
              class="field-anchor"
              :data-anchor="`field:${entityType}:${fieldName}`"
            >
              <SchemaField
                :name="fieldName"
                :schema="fieldSchema"
                :input-id="fieldInputId(fieldName)"
                :required="fieldIsRequired(fieldName, fieldSchema)"
                :error="fieldErrors[fieldName]"
                :disabled="!!fieldSchema['x-access-restricted']"
                :model-value="formData[fieldName] ?? (fieldSchema.type === 'array' ? [] : '')"
                @update:model-value="(val: any) => onFieldUpdate(fieldName, val, !!fieldSchema['x-access-restricted'])"
              />
            </div>
          </div>
        </component>
      </section>

      <div class="form-actions">
        <button
          type="submit"
          :disabled="saving"
          class="btn btn-primary"
          :data-anchor="`action:${entityType}:submit`"
          :aria-label="saving ? t('saving') : (entityId ? t('save') : t('create'))"
        >
          {{ saving ? t('saving') : (entityId ? t('save') : t('create')) }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.schema-form form {
  display: grid;
  gap: 1rem;
}
.save-advisory-review {
  padding: 1rem;
  border: 2px solid var(--color-warning, #9a6700);
  border-radius: 0.625rem;
  background: var(--color-warning-surface, #fff8c5);
}
.save-advisory-review h2 {
  margin: 0 0 0.5rem;
  font-size: 1.125rem;
}
.save-advisory-review p,
.save-advisory-review ul {
  margin: 0 0 0.75rem;
}
.form-section {
  min-width: 0;
}
.form-section__panel {
  margin: 0;
  padding: 1rem;
  border: 1px solid var(--color-border, #d8ddd9);
  border-radius: 0.625rem;
  background: var(--color-surface, #fff);
}
.form-section__panel > h2 {
  margin: 0 0 0.25rem;
  font-size: 1.125rem;
}
.form-section__description {
  margin: 0 0 1rem;
  color: var(--color-muted, #5d6670);
  font-size: 0.875rem;
}
.form-section__summary {
  display: grid;
  gap: 0.25rem;
  min-height: var(--admin-target-size, 44px);
  align-content: center;
  cursor: pointer;
  font-weight: 700;
}
.form-section__summary small {
  color: var(--color-muted, #5d6670);
  font-size: 0.8125rem;
  font-weight: 400;
}
.form-section__panel[open] > .form-section__summary {
  margin-bottom: 1rem;
}
.form-section__fields {
  display: grid;
  gap: 0.875rem;
}
.form-actions { margin-top: 0.25rem; }
.validation-summary {
  margin-bottom: 1rem;
  padding: 0.875rem 1rem;
  border: 2px solid #b42318;
  border-left-width: 0.5rem;
  border-radius: 0.25rem;
  color: #7a271a;
  background: #fffbfa;
}
.validation-summary:focus { outline: 3px solid #175cd3; outline-offset: 3px; }
.validation-summary__title { margin: 0 0 0.5rem; font-weight: 700; }
.validation-summary ul { margin: 0; padding-left: 1.25rem; }
.validation-summary a { color: #7a271a; text-decoration: underline; }
:deep(.field-error) {
  margin-top: 0.375rem;
  padding-left: 0.5rem;
  border-left: 3px solid #b42318;
  color: #7a271a;
  font-size: 0.875rem;
}
:deep(.field-input[aria-invalid="true"]) { border-color: #b42318; border-width: 2px; }
:deep(.field-input:focus-visible),
:deep(button:focus-visible),
:deep(select:focus-visible),
:deep(textarea:focus-visible) {
  outline: 3px solid #175cd3;
  outline-offset: 2px;
}
</style>

<script setup lang="ts">
import { useAdmin } from '~/composables/useAdmin'
import { useEntity } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'
import { classifyEmbedFailure, type EmbedFailure } from '~/runtime/embedLifecycle'
import type { WorkflowTransitionApplyResult } from '~/composables/useWorkflowTransitions'

const props = defineProps<{
  entityType: string
  entityId?: string
  initialBundle?: string
}>()

const emit = defineEmits<{
  saved: [resource: any]
  deleted: [entityId: string]
  ready: []
  dirty: [dirty: boolean]
  failure: [failure: EmbedFailure]
  transitioned: [result: WorkflowTransitionApplyResult]
}>()

const { t, entityLabel: translateEntityLabel } = useLanguage()
const { hasCapability, getEntity } = useAdmin()
const { remove } = useEntity()
const { schema, fetch: fetchSchema } = useSchema(props.entityType)
const successMessage = ref('')
const errorMessage = ref('')
const deleteError = ref('')
const showDeleteConfirm = ref(false)
const deleting = ref(false)
const refreshKey = ref(0)
const formDirty = ref(false)
const recordLabel = ref<string | null>(null)

const isCreate = computed(() => !props.entityId)
const entityLabel = computed(() => translateEntityLabel(props.entityType, schema.value?.title ?? props.entityType))
const workflowBound = computed(() => schema.value?.['x-workflow']?.bound === true)
const canDelete = computed(() => !!props.entityId && hasCapability(props.entityType, 'delete'))
// A type that keeps no revisions has no history surface, so asking for one
// yields 404. Withhold the panel instead of rendering it and letting it fail.
const canSeeHistory = computed(() => !!props.entityId && hasCapability(props.entityType, 'revisions'))

onMounted(() => fetchSchema(props.entityId
  ? { id: props.entityId }
  : props.initialBundle
    ? { bundle: props.initialBundle }
    : undefined))

function onLoaded(resource: any) {
  const field = getEntity(props.entityType)?.reference?.labelField
  const value = field ? resource?.attributes?.[field] : null
  recordLabel.value = typeof value === 'string' && value.trim() !== '' ? value.trim() : null
}

function onDirty(dirty: boolean) {
  formDirty.value = dirty
  emit('dirty', dirty)
}

function onSaved(resource: any) {
  if (resource?.attributes) onLoaded(resource)
  formDirty.value = false
  successMessage.value = isCreate.value ? t('entity_created') : t('entity_saved')
  errorMessage.value = ''
  refreshKey.value++
  emit('saved', resource)
}

function onError(message: string) {
  errorMessage.value = message
}

function onTransitioned(result: WorkflowTransitionApplyResult) {
  successMessage.value = t('workflow_transitioned')
  refreshKey.value++
  emit('transitioned', result)
}

async function confirmDelete() {
  if (!props.entityId || deleting.value) return
  deleting.value = true
  deleteError.value = ''
  try {
    await remove(props.entityType, props.entityId)
    emit('deleted', props.entityId)
  } catch (error: any) {
    deleteError.value = error?.data?.errors?.[0]?.detail ?? error?.message ?? t('error_deleting')
    emit('failure', classifyEmbedFailure(error))
  } finally {
    deleting.value = false
    showDeleteConfirm.value = false
  }
}
</script>

<template>
  <section class="entity-editor-workspace" :aria-busy="deleting ? 'true' : 'false'">
    <header class="entity-editor-header">
      <div>
        <p>{{ isCreate ? t('create') : t('edit') }}</p>
        <h1>{{ isCreate ? t('create_entity', { type: entityLabel }) : recordLabel || t('edit_entity', { type: entityLabel }) }}</h1>
      </div>
      <div v-if="entityId" class="entity-editor-actions">
        <WorkflowTransitionControls
          v-if="workflowBound"
          :key="`transitions-${refreshKey}`"
          :entity-type="entityType"
          :entity-id="entityId"
          :disabled="formDirty"
          @transitioned="onTransitioned"
        />
        <p v-if="formDirty && workflowBound" class="editor-publication-help" role="status">
          {{ t('editor_save_before_transition') }}
        </p>
      </div>
    </header>

    <div v-if="successMessage" class="success" role="status">{{ successMessage }}</div>
    <div v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</div>
    <div v-if="deleteError" class="error" role="alert">{{ deleteError }}</div>

    <SchemaForm
      :key="`form-${entityId ?? 'create'}`"
      :entity-type="entityType"
      :entity-id="entityId"
      :initial-bundle="initialBundle"
      @loaded="onLoaded"
      @saved="onSaved"
      @error="onError"
      @ready="emit('ready')"
      @dirty="onDirty"
      @failure="emit('failure', $event)"
    />

    <details v-if="entityId" class="entity-record-details">
      <summary>{{ t('editor_record_details') }}</summary>
      <dl><dt>{{ t('editor_record_identifier') }}</dt><dd>{{ entityId }}</dd></dl>
        <button
          v-if="canDelete"
          type="button"
          class="btn btn-danger"
          :disabled="deleting"
          @click="showDeleteConfirm = true"
        >
          {{ t('delete') }}
        </button>
    </details>

    <WorkflowTransitionHistoryTimeline
      v-if="entityId && workflowBound"
      :key="`history-${refreshKey}`"
      :entity-type="entityType"
      :entity-id="entityId"
    />

    <EntityEditorEntityRevisionRecovery
      v-if="entityId && canSeeHistory"
      :entity-type="entityType"
      :entity-id="entityId"
      compact
    />

    <CommonConfirmDialog
      :open="showDeleteConfirm"
      :message="t('confirm_delete')"
      :confirm-label="t('delete')"
      dangerous
      @cancel="showDeleteConfirm = false"
      @confirm="confirmDelete"
    />
  </section>
</template>

<style scoped>
.entity-editor-workspace {
  min-height: 100vh;
  padding: clamp(1rem, 2.4vw, 2rem);
  background: var(--color-bg, #f0eee8);
}

.entity-editor-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.entity-editor-header p {
  margin: 0 0 0.25rem;
  color: var(--color-text-muted, #5d6670);
  font-size: 0.75rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.entity-editor-header h1 { margin: 0; overflow-wrap: anywhere; }
.entity-editor-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.editor-publication-help { flex-basis: 100%; font-size: 0.875rem; }
.entity-record-details { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border, #d8d8d8); }
.entity-record-details summary { cursor: pointer; min-height: 2.75rem; }
.entity-record-details dl { margin: 0 0 1rem; }
.entity-record-details dd { margin: 0.25rem 0 0; overflow-wrap: anywhere; }

@media (max-width: 700px) {
  .entity-editor-header { flex-direction: column; }
}
</style>

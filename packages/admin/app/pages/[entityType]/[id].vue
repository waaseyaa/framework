<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'
import { useAdmin } from '~/composables/useAdmin'
import { useEntity } from '~/composables/useEntity'

const route = useRoute()
const { t, entityLabel: translateEntityLabel } = useLanguage()
const { hasCapability } = useAdmin()
const { get, remove } = useEntity()

const entityType = computed(() => route.params.entityType as string)
const entityId = computed(() => route.params.id as string)
const { schema, fetch: fetchSchema } = useSchema(entityType.value)
const entityBundle = ref<string | null>(null)
onMounted(async () => {
  const [, entityResult] = await Promise.allSettled([
    fetchSchema({ id: entityId.value }),
    get(entityType.value, entityId.value),
  ])
  if (entityResult.status === 'fulfilled') {
    const bundle = entityResult.value.attributes?.type
    entityBundle.value = typeof bundle === 'string' ? bundle : null
  }
})
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const workflowBound = computed(() => schema.value?.['x-workflow']?.bound === true)
const pageBuilderAvailable = computed(() => entityType.value === 'node' && entityBundle.value === 'page')
const { appName } = useAdminConfig()

const mode = ref<'view' | 'edit'>('view')
const successMessage = ref('')
const errorMessage = ref('')
// Bumped on a successful workflow transition to force SchemaView to re-fetch
// the entity (its cache key is `view-${entityId}`, unchanged by a transition).
const viewRefreshKey = ref(0)
const showDeleteConfirm = ref(false)
const deleting = ref(false)
const deleteError = ref('')
const canDelete = computed(() => hasCapability(entityType.value, 'delete'))

useHead({ title: computed(() => {
  const titleKey = mode.value === 'edit' ? 'edit_entity' : 'view_entity'
  return `${t(titleKey, { type: entityLabel.value })} | ${appName}`
}) })

function onSaved() {
  successMessage.value = t('entity_saved')
  mode.value = 'view'
  setTimeout(() => { successMessage.value = '' }, 3000)
}

function onError(message: string) {
  errorMessage.value = message
}

function onTransitioned() {
  successMessage.value = t('workflow_transitioned')
  viewRefreshKey.value++
  setTimeout(() => { successMessage.value = '' }, 3000)
}

async function confirmDelete() {
  if (deleting.value) return
  deleting.value = true
  deleteError.value = ''
  try {
    await remove(entityType.value, entityId.value)
    await navigateTo(`/${entityType.value}`)
  } catch (e: any) {
    deleteError.value = e?.detail ?? e?.data?.errors?.[0]?.detail ?? e?.message ?? t('error_deleting')
  } finally {
    deleting.value = false
    showDeleteConfirm.value = false
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1 v-if="mode === 'view'">{{ t('view_entity', { type: entityLabel }) }} #{{ entityId }}</h1>
      <h1 v-else>{{ t('edit_entity', { type: entityLabel }) }} #{{ entityId }}</h1>
      <div class="page-header-actions">
        <WorkflowTransitionControls
          v-if="workflowBound"
          :entity-type="entityType"
          :entity-id="entityId"
          @transitioned="onTransitioned"
        />
        <NuxtLink
          v-if="pageBuilderAvailable"
          :to="`/page-builder/page/${encodeURIComponent(entityId)}`"
          class="btn btn-primary"
          data-testid="detail-page-builder"
        >
          {{ t('page_builder_open') }}
        </NuxtLink>
        <button
          v-if="mode === 'view'"
          class="btn btn-primary"
          @click="mode = 'edit'"
        >
          {{ t('edit') }}
        </button>
        <button
          v-if="mode === 'edit'"
          class="btn"
          @click="mode = 'view'"
        >
          {{ t('cancel') }}
        </button>
        <button
          v-if="mode === 'view' && canDelete"
          type="button"
          class="btn btn-danger"
          data-testid="detail-delete"
          :data-anchor="`action:${entityType}:delete`"
          :disabled="deleting"
          @click="showDeleteConfirm = true"
        >
          {{ t('delete') }}
        </button>
        <NuxtLink :to="`/${entityType}`" class="btn">
          {{ t('back_to_list') }}
        </NuxtLink>
      </div>
    </div>

    <div v-if="successMessage" class="success">{{ successMessage }}</div>
    <div v-if="errorMessage" class="error">{{ errorMessage }}</div>
    <div v-if="deleteError" class="error" role="alert">{{ deleteError }}</div>

    <SchemaView
      v-if="mode === 'view'"
      :key="`view-${entityId}-${viewRefreshKey}`"
      :entity-type="entityType"
      :entity-id="entityId"
    />

    <SchemaForm
      v-else
      :entity-type="entityType"
      :entity-id="entityId"
      @saved="onSaved"
      @error="onError"
    />

    <WorkflowTransitionHistoryTimeline
      v-if="workflowBound"
      :key="`history-${entityType}-${entityId}`"
      :entity-type="entityType"
      :entity-id="entityId"
    />
    <CommonConfirmDialog
      :open="showDeleteConfirm"
      :message="t('confirm_delete')"
      :confirm-label="t('delete')"
      dangerous
      @cancel="showDeleteConfirm = false"
      @confirm="confirmDelete"
    />
  </div>
</template>

<style scoped>
.page-header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
  min-width: 0;
  max-width: 100%;
}
</style>

<script setup lang="ts">
import { postEmbedLifecycle, type EmbedFailure } from '~/runtime/embedLifecycle'
import type { WorkflowTransitionApplyResult } from '~/composables/useWorkflowTransitions'
definePageMeta({ layout: false })

const route = useRoute()
const entityType = computed(() => String(route.params.entityType))
const routeId = computed(() => String(route.params.id))
const entityId = computed(() => routeId.value === 'create' ? undefined : routeId.value)
const initialBundle = computed(() => typeof route.query.bundle === 'string' ? route.query.bundle : undefined)
const { t } = useLanguage()
const { appName } = useAdminConfig()

useHead({ title: computed(() => `${entityId.value ? t('edit') : t('create')} | ${appName}`) })

function notify(type: 'saved' | 'deleted', id: string) {
  if (window.parent === window) return
  window.parent.postMessage({
    type: `waaseyaa.entity-editor.${type}`,
    entityType: entityType.value,
    entityId: id,
  }, window.location.origin)
}

function lifecycle(event: 'ready' | 'saved' | 'deleted', id?: string) {
  postEmbedLifecycle({
    event,
    surface: 'entity-editor',
    entityType: entityType.value,
    ...(id ? { entityId: id } : entityId.value ? { entityId: entityId.value } : {}),
  })
}

function onDirty(dirty: boolean) {
  postEmbedLifecycle({
    event: 'dirty',
    surface: 'entity-editor',
    entityType: entityType.value,
    ...(entityId.value ? { entityId: entityId.value } : {}),
    dirty,
  })
}

function onFailure(failure: EmbedFailure) {
  postEmbedLifecycle({
    event: 'failure',
    surface: 'entity-editor',
    entityType: entityType.value,
    ...(entityId.value ? { entityId: entityId.value } : {}),
    failure,
  })
}

function onTransitioned(result: WorkflowTransitionApplyResult) {
  postEmbedLifecycle({
    event: 'transitioned',
    surface: 'entity-editor',
    entityType: entityType.value,
    ...(entityId.value ? { entityId: entityId.value } : {}),
    transition: {
      state: result.to,
      publicChanged: result.public_changed,
    },
  })
}

async function onSaved(resource: any) {
  const id = String(resource?.id ?? entityId.value ?? '')
  if (!id) return
  lifecycle('saved', id)
  notify('saved', id)
  if (!entityId.value) {
    await navigateTo(`/entity-editor-embed/${encodeURIComponent(entityType.value)}/${encodeURIComponent(id)}`, { replace: true })
  }
}

function onDeleted(id: string) {
  lifecycle('deleted', id)
  notify('deleted', id)
}
</script>

<template>
  <main class="entity-editor-embed" data-entity-editor-client="waaseyaa-admin">
    <EntityEditorWorkspace
      :entity-type="entityType"
      :entity-id="entityId"
      :initial-bundle="initialBundle"
      @saved="onSaved"
      @deleted="onDeleted"
      @ready="lifecycle('ready')"
      @dirty="onDirty"
      @failure="onFailure"
      @transitioned="onTransitioned"
    />
  </main>
</template>

<style scoped>
.entity-editor-embed {
  min-height: 100vh;
  background: var(--color-bg, #f0eee8);
}
</style>

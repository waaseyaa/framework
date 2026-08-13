<script setup lang="ts">
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

async function onSaved(resource: any) {
  const id = String(resource?.id ?? entityId.value ?? '')
  if (!id) return
  notify('saved', id)
  if (!entityId.value) {
    await navigateTo(`/entity-editor-embed/${encodeURIComponent(entityType.value)}/${encodeURIComponent(id)}`, { replace: true })
  }
}

function onDeleted(id: string) {
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
    />
  </main>
</template>

<style scoped>
.entity-editor-embed {
  min-height: 100vh;
  background: var(--color-bg, #f0eee8);
}
</style>

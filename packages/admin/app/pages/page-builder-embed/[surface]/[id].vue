<script setup lang="ts">
import { postEmbedLifecycle, type EmbedFailure } from '~/runtime/embedLifecycle'
definePageMeta({ layout: false })

const route = useRoute()
const surface = computed(() => String(route.params.surface))
const entityId = computed(() => String(route.params.id))
const { t } = useLanguage()
const { appName } = useAdminConfig()

useHead({ title: computed(() => `${t('page_builder_title')} | ${appName}`) })

function lifecycle(event: 'ready' | 'saved') {
  postEmbedLifecycle({ event, surface: 'page-builder', surfaceId: surface.value, entityId: entityId.value })
}

function onDirty(dirty: boolean) {
  postEmbedLifecycle({ event: 'dirty', surface: 'page-builder', surfaceId: surface.value, entityId: entityId.value, dirty })
}

function onFailure(failure: EmbedFailure) {
  postEmbedLifecycle({ event: 'failure', surface: 'page-builder', surfaceId: surface.value, entityId: entityId.value, failure })
}
</script>

<template>
  <main class="page-builder-embed" data-page-builder-client="waaseyaa-admin">
    <PageBuilderWorkspace
      :surface="surface"
      :entity-id="entityId"
      @ready="lifecycle('ready')"
      @dirty="onDirty"
      @saved="lifecycle('saved')"
      @failure="onFailure"
    />
  </main>
</template>

<style scoped>
.page-builder-embed {
  min-height: 100vh;
  overflow: hidden;
  background: var(--color-bg, #f0eee8);
}

.page-builder-embed :deep(.page-builder) {
  min-height: 100vh;
  margin: 0;
}
</style>

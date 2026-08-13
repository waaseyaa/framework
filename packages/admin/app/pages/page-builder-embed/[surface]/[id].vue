<script setup lang="ts">
definePageMeta({ layout: false })

const route = useRoute()
const surface = computed(() => String(route.params.surface))
const entityId = computed(() => String(route.params.id))
const { t } = useLanguage()
const { appName } = useAdminConfig()

useHead({ title: computed(() => `${t('page_builder_title')} | ${appName}`) })
</script>

<template>
  <main class="page-builder-embed" data-page-builder-client="waaseyaa-admin">
    <PageBuilderWorkspace :surface="surface" :entity-id="entityId" />
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

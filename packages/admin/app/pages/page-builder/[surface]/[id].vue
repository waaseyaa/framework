<script setup lang="ts">
const route = useRoute()
const surface = computed(() => String(route.params.surface))
const entityId = computed(() => String(route.params.id))
const { t } = useLanguage()
const { appName } = useAdminConfig()

useHead({ title: computed(() => `${t('page_builder_title')} | ${appName}`) })
</script>

<template>
  <PageBuilderWorkspace :surface="surface" :entity-id="entityId" />
</template>

<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'

const route = useRoute()
const { t, entityLabel: translateEntityLabel } = useLanguage()
const entityType = computed(() => route.params.entityType as string)
const entityId = computed(() => route.params.id as string)
const { schema, fetch: fetchSchema } = useSchema(entityType.value)
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const { appName } = useAdminConfig()

useHead({ title: computed(() => `${t('history_title')} | ${entityLabel.value} | ${appName}`) })
onMounted(() => fetchSchema({ id: entityId.value }))
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('history_for', { type: entityLabel }) }}</h1>
      <NuxtLink :to="`/${entityType}/${entityId}`" class="btn">{{ t('history_back_to_record') }}</NuxtLink>
    </div>
    <EntityEditorEntityRevisionRecovery :entity-type="entityType" :entity-id="entityId" />
  </div>
</template>

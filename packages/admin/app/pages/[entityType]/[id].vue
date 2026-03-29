<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'

const route = useRoute()
const { t, entityLabel: translateEntityLabel } = useLanguage()

const entityType = computed(() => route.params.entityType as string)
const { schema, fetch: fetchSchema } = useSchema(entityType.value)
onMounted(() => fetchSchema())
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const config = useRuntimeConfig()
const entityId = computed(() => route.params.id as string)

const mode = ref<'view' | 'edit'>('view')
const successMessage = ref('')
const errorMessage = ref('')

useHead({ title: computed(() => {
  const titleKey = mode.value === 'edit' ? 'edit_entity' : 'view_entity'
  return `${t(titleKey, { type: entityLabel.value })} | ${config.public.appName}`
}) })

function onSaved() {
  successMessage.value = t('entity_saved')
  mode.value = 'view'
  setTimeout(() => { successMessage.value = '' }, 3000)
}

function onError(message: string) {
  errorMessage.value = message
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1 v-if="mode === 'view'">{{ t('view_entity', { type: entityLabel }) }} #{{ entityId }}</h1>
      <h1 v-else>{{ t('edit_entity', { type: entityLabel }) }} #{{ entityId }}</h1>
      <div class="page-header-actions">
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
        <NuxtLink :to="`/${entityType}`" class="btn">
          {{ t('back_to_list') }}
        </NuxtLink>
      </div>
    </div>

    <div v-if="successMessage" class="success">{{ successMessage }}</div>
    <div v-if="errorMessage" class="error">{{ errorMessage }}</div>

    <SchemaView
      v-if="mode === 'view'"
      :key="`view-${entityId}`"
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
  </div>
</template>

<style scoped>
.page-header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
}
</style>

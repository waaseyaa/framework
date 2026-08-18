<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'
import { readBundleScope } from '~/runtime/bundleScope'

const route = useRoute()
const router = useRouter()
const { t, entityLabel: translateEntityLabel } = useLanguage()

const entityType = computed(() => route.params.entityType as string)
// A consumer may deep-link "create a post" rather than only "create a node"
// (#2418). An unusable value degrades to the unscoped create form; whether the
// bundle is permitted is settled by the schema request SchemaForm makes.
const requestedBundle = computed(() => readBundleScope(route.query))
const { schema, fetch: fetchSchema } = useSchema(entityType.value)
onMounted(() => fetchSchema())
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const { appName } = useAdminConfig()
useHead({ title: computed(() => `${t('create_entity', { type: entityLabel.value })} | ${appName}`) })
const successMessage = ref('')
const errorMessage = ref('')

function onSaved(resource: any) {
  successMessage.value = t('entity_created')
  setTimeout(() => {
    router.push(`/${entityType.value}/${resource.id}`)
  }, 500)
}

function onError(message: string) {
  errorMessage.value = message
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('create_entity', { type: entityLabel }) }}</h1>
      <NuxtLink :to="`/${entityType}`" class="btn">
        {{ t('back_to_list') }}
      </NuxtLink>
    </div>

    <div v-if="successMessage" class="success">{{ successMessage }}</div>
    <div v-if="errorMessage" class="error">{{ errorMessage }}</div>

    <SchemaForm
      :entity-type="entityType"
      :initial-bundle="requestedBundle"
      @saved="onSaved"
      @error="onError"
    />
  </div>
</template>

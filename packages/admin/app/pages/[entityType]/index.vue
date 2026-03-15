<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useSchema } from '~/composables/useSchema'
import { useAdmin } from '~/composables/useAdmin'

const route = useRoute()
const { t, entityLabel: translateEntityLabel } = useLanguage()
const { catalog, getEntity, hasCapability } = useAdmin()

const entityType = computed(() => route.params.entityType as string)
const { schema, loading, error, fetch: fetchSchema } = useSchema(entityType.value)
const canCreate = computed(() => hasCapability(entityType.value, 'create'))
const typeInfo = computed(() => getEntity(entityType.value) ?? null)
const actionLoading = ref(false)
const actionError = ref<string | null>(null)
const showDisableConfirm = ref(false)

const isDefaultNote = computed(() => entityType.value === 'note')
const typeDisabled = computed(() => typeInfo.value?.disabled ?? false)
const enabledTypeCount = computed(() => catalog.filter((type) => !type.disabled).length)
const showLifecycleControls = computed(() => isDefaultNote.value && typeInfo.value !== null)
const showDisableWarning = computed(
  () => !typeDisabled.value && enabledTypeCount.value <= 1,
)

// TODO: move to admin operations adapter
async function disableType(force = false) {
  if (actionLoading.value) return
  actionLoading.value = true
  actionError.value = null
  try {
    const query = force ? '?force=1' : ''
    await $fetch(`/api/entity-types/${entityType.value}/disable${query}`, { method: 'POST' })
    showDisableConfirm.value = false
  } catch (e: any) {
    actionError.value = e?.data?.errors?.[0]?.detail ?? e?.message ?? t('error_generic')
  } finally {
    actionLoading.value = false
  }
}

// TODO: move to admin operations adapter
async function enableType() {
  if (actionLoading.value) return
  actionLoading.value = true
  actionError.value = null
  try {
    await $fetch(`/api/entity-types/${entityType.value}/enable`, { method: 'POST' })
  } catch (e: any) {
    actionError.value = e?.data?.errors?.[0]?.detail ?? e?.message ?? t('error_generic')
  } finally {
    actionLoading.value = false
  }
}

onMounted(async () => {
  await fetchSchema()
})
const entityLabel = computed(() => translateEntityLabel(entityType.value, schema.value?.title ?? entityType.value))
const config = useRuntimeConfig()
useHead({ title: computed(() => `${entityLabel.value} | ${config.public.appName}`) })
</script>

<template>
  <div>
    <template v-if="!loading && error">
      <div class="page-header">
        <h1>{{ t('error_not_found') }}</h1>
      </div>
      <p class="error">{{ error }}</p>
      <NuxtLink to="/" class="btn">← {{ t('dashboard') }}</NuxtLink>
    </template>

    <template v-else>
      <div class="page-header">
        <div class="page-title">
          <h1>{{ entityLabel }}</h1>
          <span v-if="showLifecycleControls && typeDisabled" class="status-pill">
            {{ t('type_disabled') }}
          </span>
        </div>
        <div class="page-actions">
          <button
            v-if="showLifecycleControls && !typeDisabled"
            class="btn btn-danger"
            :disabled="actionLoading"
            @click="showDisableConfirm = true"
          >
            {{ t('disable_type') }}
          </button>
          <button
            v-else-if="showLifecycleControls && typeDisabled"
            class="btn"
            :disabled="actionLoading"
            @click="enableType"
          >
            {{ t('enable_type') }}
          </button>
          <NuxtLink v-if="canCreate" :to="`/${entityType}/create`" class="btn btn-primary">
            {{ t('create_new') }}
          </NuxtLink>
        </div>
      </div>

      <div v-if="actionError" class="error">{{ actionError }}</div>

      <SchemaList :entity-type="entityType" />
    </template>
  </div>

  <div v-if="showDisableConfirm" class="modal-backdrop" role="dialog" aria-modal="true">
    <div class="modal-card">
      <h2 class="modal-title">{{ t('disable_type_title', { type: entityLabel }) }}</h2>
      <p class="modal-body">{{ t('disable_type_body', { type: entityLabel }) }}</p>
      <p v-if="showDisableWarning" class="modal-warning">
        {{ t('disable_type_warning') }}
      </p>
      <div class="modal-actions">
        <button class="btn" :disabled="actionLoading" @click="showDisableConfirm = false">
          {{ t('cancel') }}
        </button>
        <button
          class="btn btn-danger"
          :disabled="actionLoading"
          @click="disableType(showDisableWarning)"
        >
          {{ showDisableWarning ? t('disable_anyway') : t('disable_type') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-title {
  display: flex;
  align-items: center;
  gap: 12px;
}

.page-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.status-pill {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  background: rgba(196, 0, 0, 0.1);
  color: var(--color-danger, #c00);
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 40;
}

.modal-card {
  background: var(--color-surface);
  border-radius: 12px;
  border: 1px solid var(--color-border);
  padding: 20px;
  max-width: 420px;
  width: 100%;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2);
}

.modal-title {
  font-size: 18px;
  margin-bottom: 8px;
}

.modal-body {
  color: var(--color-text);
  margin-bottom: 12px;
}

.modal-warning {
  color: var(--color-danger, #c00);
  font-size: 13px;
  margin-bottom: 16px;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}
</style>

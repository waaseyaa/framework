<script setup lang="ts">
import { computed, ref } from 'vue'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const route = useRoute()

const policyId = computed(() => String(route.params.id ?? ''))
const isNew = computed(() => policyId.value === 'new' || policyId.value === '')

const saveError = ref<string | null>(null)

async function onSaved(): Promise<void> {
  await navigateTo('/classification/policies')
}

function onError(message: string): void {
  saveError.value = message
}
</script>

<template>
  <div class="retention-policy-detail" data-testid="retention-policy-detail">
    <header class="page-header">
      <h1>
        {{ isNew ? t('create_new') : t('classification_policy_edit') }}
      </h1>
      <NuxtLink to="/classification/policies" class="btn">
        {{ t('back_to_list') }}
      </NuxtLink>
    </header>

    <div v-if="saveError" class="error" data-testid="policy-save-error">{{ saveError }}</div>

    <SchemaForm
      entity-type="retention_policy"
      :entity-id="isNew ? undefined : policyId"
      @saved="onSaved"
      @error="onError"
    />
  </div>
</template>

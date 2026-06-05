<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRetentionPolicies } from '~/composables/useRetentionPolicies'
import { useLanguage } from '~/composables/useLanguage'
import type { RetentionPolicy } from '~/composables/useRetentionPolicies'

const { t } = useLanguage()
const { policies, loading, error, fetchPolicies, deletePolicy } = useRetentionPolicies()

const pendingDelete = ref<RetentionPolicy | null>(null)

onMounted(() => fetchPolicies())

const byAction = computed(() => {
  const counts = { purge: 0, redact: 0, 'hold-flag': 0 }
  for (const policy of policies.value) {
    if (policy.action === 'purge') counts.purge++
    else if (policy.action === 'redact') counts.redact++
    else if (policy.action === 'hold-flag') counts['hold-flag']++
  }
  return counts
})

function actionLabel(action: string): string {
  switch (action) {
    case 'purge': return t('classification_policy_action_purge')
    case 'redact': return t('classification_policy_action_redact')
    case 'hold-flag': return t('classification_policy_action_hold_flag')
    default: return action
  }
}

function askDelete(policy: RetentionPolicy): void {
  pendingDelete.value = policy
}

function cancelDelete(): void {
  pendingDelete.value = null
}

async function confirmDelete(): Promise<void> {
  if (pendingDelete.value === null) {
    return
  }
  const id = pendingDelete.value.id
  pendingDelete.value = null
  await deletePolicy(id)
}
</script>

<template>
  <div class="retention-policies" data-testid="retention-policies">
    <header class="page-header">
      <h1>{{ t('classification_policies_title') }}</h1>
      <NuxtLink to="/classification/policies/new" class="btn btn-primary" data-testid="policy-create">
        {{ t('create_new') }}
      </NuxtLink>
    </header>

    <section class="summary-cards">
      <div class="card">
        <span class="card-value" data-testid="summary-total">{{ policies.length }}</span>
        <span class="card-label">{{ t('classification_policies_title') }}</span>
      </div>
      <div class="card">
        <span class="card-value">{{ byAction.purge }}</span>
        <span class="card-label">{{ t('classification_policy_action_purge') }}</span>
      </div>
      <div class="card">
        <span class="card-value">{{ byAction.redact }}</span>
        <span class="card-label">{{ t('classification_policy_action_redact') }}</span>
      </div>
      <div class="card">
        <span class="card-value">{{ byAction['hold-flag'] }}</span>
        <span class="card-label">{{ t('classification_policy_action_hold_flag') }}</span>
      </div>
    </section>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>
    <div v-else-if="error" class="error" data-testid="policy-error">{{ error }}</div>
    <div v-else-if="policies.length === 0" class="empty" data-testid="policy-empty">
      {{ t('classification_policies_empty') }}
    </div>
    <table v-else class="policy-table" data-testid="policy-table">
      <thead>
        <tr>
          <th>{{ t('classification_policy_name') }}</th>
          <th>{{ t('classification_policy_applies_to') }}</th>
          <th>{{ t('classification_policy_action') }}</th>
          <th>{{ t('classification_policy_trigger_kind') }}</th>
          <th>{{ t('classification_policy_trigger_value') }}</th>
          <th>{{ t('actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="policy in policies" :key="policy.id" data-testid="policy-row">
          <td>{{ policy.name }}</td>
          <td>{{ policy.applies_to.join(', ') }}</td>
          <td>{{ actionLabel(policy.action) }}</td>
          <td>{{ policy.trigger_kind }}</td>
          <td>{{ policy.trigger_value }}</td>
          <td class="row-actions">
            <NuxtLink :to="`/classification/policies/${policy.id}`" class="btn btn-sm">
              {{ t('classification_policy_edit') }}
            </NuxtLink>
            <button type="button" class="btn btn-sm btn-danger" @click="askDelete(policy)">
              {{ t('classification_policy_delete') }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="pendingDelete !== null" class="confirm-dialog" data-testid="policy-confirm-delete">
      <p>{{ t('classification_policy_confirm_delete') }}</p>
      <div class="confirm-actions">
        <button type="button" class="btn btn-danger" @click="confirmDelete">
          {{ t('classification_policy_delete') }}
        </button>
        <button type="button" class="btn" @click="cancelDelete">
          {{ t('back_to_list') }}
        </button>
      </div>
    </div>
  </div>
</template>

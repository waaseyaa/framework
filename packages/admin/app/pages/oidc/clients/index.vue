<script setup lang="ts">
import type { OidcClient } from '~/composables/useOidcClients'
import { useLanguage } from '~/composables/useLanguage'

const { list, remove } = useOidcClients()
const { t } = useLanguage()

const clients = ref<OidcClient[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const deletingId = ref<string | null>(null)
const pendingDeleteId = ref<string | null>(null)

async function loadClients() {
  loading.value = true
  error.value = null
  try {
    clients.value = await list()
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : t('oidc.clients.errorLoad')
  } finally {
    loading.value = false
  }
}

async function deleteClient(id: string) {
  deletingId.value = id
  try {
    await remove(id)
    clients.value = clients.value.filter(c => c.id !== id)
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : t('oidc.clients.errorDelete')
  } finally {
    deletingId.value = null
  }
}

async function confirmDeleteClient() {
  const id = pendingDeleteId.value
  pendingDeleteId.value = null
  if (id) await deleteClient(id)
}

onMounted(loadClients)
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-semibold">{{ t('oidc.clients.title') }}</h1>
      <NuxtLink to="/oidc/clients/new" class="btn-primary">
        {{ t('oidc.clients.addNew') }}
      </NuxtLink>
    </div>

    <div v-if="error" class="alert-error mb-4">{{ error }}</div>
    <div v-if="loading" class="text-gray-500">{{ t('common.loading') }}</div>

    <table v-else-if="clients.length > 0" class="w-full border-collapse">
      <thead>
        <tr class="border-b">
          <th class="text-left py-2 pr-4">{{ t('oidc.clients.fields.name') }}</th>
          <th class="text-left py-2 pr-4">{{ t('oidc.clients.fields.clientId') }}</th>
          <th class="text-left py-2 pr-4">{{ t('oidc.clients.fields.confidential') }}</th>
          <th class="text-left py-2">{{ t('common.actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="client in clients" :key="client.id" class="border-b hover:bg-gray-50">
          <td class="py-2 pr-4">{{ client.name }}</td>
          <td class="py-2 pr-4 font-mono text-sm">{{ client.client_id }}</td>
          <td class="py-2 pr-4">{{ client.is_confidential ? t('common.yes') : t('common.no') }}</td>
          <td class="py-2 space-x-2">
            <NuxtLink :to="`/oidc/clients/${client.id}`" class="link">
              {{ t('common.edit') }}
            </NuxtLink>
            <button
              class="link text-red-600"
              :disabled="deletingId === client.id"
              @click="pendingDeleteId = client.id"
            >
              {{ t('common.delete') }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <p v-else class="text-gray-500">{{ t('oidc.clients.empty') }}</p>
    <CommonConfirmDialog
      :open="pendingDeleteId !== null"
      :message="t('oidc.clients.confirmDelete')"
      :confirm-label="t('common.delete')"
      dangerous
      @cancel="pendingDeleteId = null"
      @confirm="confirmDeleteClient"
    />
  </div>
</template>

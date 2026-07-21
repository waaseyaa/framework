<script setup lang="ts">
import type { OidcClient, OidcClientInput } from '~/composables/useOidcClients'
import { useLanguage } from '~/composables/useLanguage'

const route = useRoute()
const { get, create, update, regenerateSecret } = useOidcClients()
const { t } = useLanguage()

const isNew = computed(() => route.params.id === 'new')
const clientId = computed(() => String(route.params.id))

const client = ref<OidcClient | null>(null)
const loading = ref(!isNew.value)
const saving = ref(false)
const error = ref<string | null>(null)
const revealedSecret = ref<string | null>(null)
const regenerating = ref(false)
const confirmRegenerateOpen = ref(false)

// Form fields
const form = ref<OidcClientInput>({
  name: '',
  client_id: '',
  redirect_uris: [],
  scopes: ['openid'],
  grant_types: ['authorization_code'],
  is_confidential: false,
})

const redirectUrisText = computed({
  get: () => (form.value.redirect_uris ?? []).join('\n'),
  set: (val: string) => {
    form.value.redirect_uris = val.split('\n').map(s => s.trim()).filter(Boolean)
  },
})

async function load() {
  if (isNew.value) return
  loading.value = true
  error.value = null
  try {
    client.value = await get(clientId.value)
    form.value = {
      name: client.value.name,
      client_id: client.value.client_id,
      redirect_uris: [...client.value.redirect_uris],
      scopes: [...client.value.scopes],
      grant_types: [...client.value.grant_types],
      is_confidential: client.value.is_confidential,
    }
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : t('oidc.clients.errorLoad')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = null
  revealedSecret.value = null
  try {
    if (isNew.value) {
      const created = await create(form.value)
      if (created.client_secret) {
        revealedSecret.value = created.client_secret
      }
      await navigateTo(`/oidc/clients/${created.id}`)
      client.value = created
    } else {
      const updated = await update(clientId.value, form.value)
      client.value = updated
    }
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : t('oidc.clients.errorSave')
  } finally {
    saving.value = false
  }
}

async function doRegenerateSecret() {
  confirmRegenerateOpen.value = false
  regenerating.value = true
  revealedSecret.value = null
  try {
    const updated = await regenerateSecret(clientId.value)
    if (updated.client_secret) {
      revealedSecret.value = updated.client_secret
    }
  } catch (e: unknown) {
    error.value = e instanceof Error ? e.message : t('oidc.clients.errorRegenerate')
  } finally {
    regenerating.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="p-6 max-w-2xl">
    <div class="flex items-center gap-4 mb-6">
      <NuxtLink to="/oidc/clients" class="link text-sm">← {{ t('oidc.clients.backToList') }}</NuxtLink>
      <h1 class="text-2xl font-semibold">
        {{ isNew ? t('oidc.clients.titleNew') : t('oidc.clients.titleEdit') }}
      </h1>
    </div>

    <div v-if="error" class="alert-error mb-4">{{ error }}</div>
    <div v-if="loading" class="text-gray-500">{{ t('common.loading') }}</div>

    <div v-if="revealedSecret" class="bg-yellow-50 border border-yellow-300 rounded p-4 mb-4">
      <p class="font-semibold text-yellow-800 mb-1">{{ t('oidc.clients.secretRevealed') }}</p>
      <code class="block font-mono text-sm break-all">{{ revealedSecret }}</code>
      <p class="text-xs text-yellow-700 mt-1">{{ t('oidc.clients.secretRevealedOnce') }}</p>
    </div>

    <form v-if="!loading" class="space-y-4" @submit.prevent="save">
      <div>
        <label class="block text-sm font-medium mb-1">{{ t('oidc.clients.fields.name') }}</label>
        <input v-model="form.name" type="text" class="input w-full" required />
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">{{ t('oidc.clients.fields.clientId') }}</label>
        <input
          v-model="form.client_id"
          type="text"
          class="input w-full font-mono"
          :readonly="!isNew"
          required
        />
        <p v-if="!isNew" class="text-xs text-gray-500 mt-1">{{ t('oidc.clients.clientIdReadonly') }}</p>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">{{ t('oidc.clients.fields.redirectUris') }}</label>
        <textarea v-model="redirectUrisText" class="input w-full font-mono text-sm" rows="3" />
        <p class="text-xs text-gray-500 mt-1">{{ t('oidc.clients.redirectUrisHint') }}</p>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">{{ t('oidc.clients.fields.confidential') }}</label>
        <input v-model="form.is_confidential" type="checkbox" class="mr-2" />
        {{ t('oidc.clients.confidentialHint') }}
      </div>

      <div class="flex gap-3 pt-2">
        <button type="submit" class="btn-primary" :disabled="saving">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <NuxtLink to="/oidc/clients" class="btn-secondary">{{ t('common.cancel') }}</NuxtLink>
      </div>
    </form>

    <div v-if="!isNew && !loading" class="mt-8 pt-6 border-t">
      <h2 class="text-lg font-semibold mb-2">{{ t('oidc.clients.dangerZone') }}</h2>
      <p class="text-sm text-gray-600 mb-3">{{ t('oidc.clients.regenerateHint') }}</p>
      <button class="btn-danger" :disabled="regenerating" @click="confirmRegenerateOpen = true">
        {{ regenerating ? t('common.working') : t('oidc.clients.regenerateSecret') }}
      </button>
    </div>
    <CommonConfirmDialog
      :open="confirmRegenerateOpen"
      :message="t('oidc.clients.confirmRegenerate')"
      @cancel="confirmRegenerateOpen = false"
      @confirm="doRegenerateSecret"
    />
  </div>
</template>

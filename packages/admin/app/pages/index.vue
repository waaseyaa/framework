<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useEntity } from '~/composables/useEntity'
import { useAdmin } from '~/composables/useAdmin'
import { TransportError } from '~/contracts/transport'
import OnboardingPrompt from '~/components/onboarding/OnboardingPrompt.vue'

const { t, entityLabel } = useLanguage()
const config = useRuntimeConfig()
const { catalog } = useAdmin()
useHead({ title: computed(() => `${t('dashboard')} | ${config.public.appName}`) })

const onboardingReady = ref(false)
const showOnboarding = ref(false)
const onboardingError = ref<string | null>(null)
const { list } = useEntity()

/** Prefer `node_type` when present (stock Waaseyaa); otherwise first listable catalog type (e.g. Minoo). */
const onboardingProbeTypeId = computed(() => {
  if (catalog.some(e => e.id === 'node_type')) {
    return 'node_type'
  }
  const first = catalog.find(e => e.capabilities.list)
  return first?.id ?? null
})

const onboardingNotePath = computed(() =>
  catalog.some(e => e.id === 'note') ? '/note/create' : '/',
)

const onboardingCustomTypePath = computed(() => {
  if (catalog.some(e => e.id === 'node_type' && e.capabilities.create)) {
    return '/node_type/create'
  }
  const first = catalog.find(e => e.capabilities.create)
  return first ? `/${first.id}/create` : '/node_type/create'
})

onMounted(async () => {
  onboardingReady.value = false
  onboardingError.value = null
  const typeId = onboardingProbeTypeId.value
  if (typeId === null) {
    showOnboarding.value = false
    onboardingReady.value = true
    return
  }
  try {
    const result = await list(typeId, { page: { offset: 0, limit: 1 } })
    const total = typeof result.meta?.total === 'number' ? result.meta.total : result.data.length
    showOnboarding.value = total === 0
  } catch (e: unknown) {
    if (e instanceof TransportError && e.status === 404) {
      showOnboarding.value = false
    } else {
      console.error('[Waaseyaa] Failed to detect onboarding state:', e)
      onboardingError.value = (e as TransportError).detail
        ?? (e as Error).message
        ?? (e as { data?: { errors?: Array<{ detail?: string }> } })?.data?.errors?.[0]?.detail
        ?? null
    }
  } finally {
    onboardingReady.value = true
  }
})
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('dashboard') }}</h1>
    </div>

    <OnboardingPrompt
      v-if="onboardingReady && showOnboarding"
      :docs-url="config.public.docsUrl"
      :note-path="onboardingNotePath"
      :custom-type-path="onboardingCustomTypePath"
    />
    <div v-else-if="onboardingReady && onboardingError" class="error">{{ onboardingError }}</div>

    <IngestSummaryWidget />

    <div class="card-grid">
      <NuxtLink
        v-for="et in catalog"
        :key="et.id"
        :to="`/${et.id}`"
        class="card"
      >
        <h2 class="card-title">{{ entityLabel(et.id, et.label) }}</h2>
        <p v-if="et.description" class="card-sub">{{ et.description }}</p>
      </NuxtLink>
    </div>
  </div>
</template>

<style scoped>
.card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}
.card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 20px;
  text-decoration: none;
  color: var(--color-text);
  transition: border-color 0.15s;
}
.card:hover { border-color: var(--color-primary); }
.card-title { font-size: 18px; margin-bottom: 4px; }
.card-sub { font-size: 13px; color: var(--color-muted); }
</style>

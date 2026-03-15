<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useEntity } from '~/composables/useEntity'
import { useAdmin } from '~/composables/useAdmin'
import OnboardingPrompt from '~/components/onboarding/OnboardingPrompt.vue'

const { t, entityLabel } = useLanguage()
const config = useRuntimeConfig()
const { catalog } = useAdmin()
useHead({ title: computed(() => `${t('dashboard')} | ${config.public.appName}`) })

const onboardingReady = ref(false)
const showOnboarding = ref(false)
const onboardingError = ref<string | null>(null)
const { list } = useEntity()

onMounted(async () => {
  onboardingReady.value = false
  onboardingError.value = null
  try {
    const result = await list('node_type', { page: { offset: 0, limit: 1 } })
    const total = typeof result.meta?.total === 'number' ? result.meta.total : result.data.length
    showOnboarding.value = total === 0
  } catch (e: unknown) {
    console.error('[Waaseyaa] Failed to detect onboarding state:', e)
    onboardingError.value = (e as any)?.data?.errors?.[0]?.detail ?? (e instanceof Error ? e.message : null)
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
      note-path="/note/create"
      custom-type-path="/node_type/create"
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

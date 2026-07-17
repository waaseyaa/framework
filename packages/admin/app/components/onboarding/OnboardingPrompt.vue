<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

defineProps<{
  docsUrl: string
  notePath: string
  customTypePath: string
}>()

const { t } = useLanguage()
</script>

<template>
  <section class="onboarding">
    <div class="onboarding-card">
      <h2 class="onboarding-title">{{ t('onboarding_title') }}</h2>
      <p class="onboarding-body">{{ t('onboarding_body') }}</p>
      <div class="onboarding-actions">
        <NuxtLink :to="notePath" class="btn btn-primary">{{ t('onboarding_use_note') }}</NuxtLink>
        <NuxtLink :to="customTypePath" class="btn">{{ t('onboarding_create_type') }}</NuxtLink>
      </div>
      <a :href="docsUrl" class="onboarding-link touch-target" target="_blank" rel="noreferrer">
        {{ t('onboarding_quickstart') }}
      </a>
    </div>
  </section>
</template>

<style scoped>
.onboarding {
  margin: 16px 0 24px;
}

.onboarding-card {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px;
  background: linear-gradient(135deg, rgba(242, 246, 255, 0.7), rgba(255, 255, 255, 0.9));
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
}

.onboarding-title {
  font-size: 20px;
  margin-bottom: 8px;
}

.onboarding-body {
  color: var(--color-muted);
  margin-bottom: 16px;
}

.onboarding-actions {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.onboarding-link {
  font-size: 13px;
  color: var(--color-primary);
  text-decoration: none;
}

.onboarding-link:hover {
  text-decoration: underline;
}
</style>

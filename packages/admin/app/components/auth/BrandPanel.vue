<script setup lang="ts">
const props = defineProps<{
  tagline?: string
  logoUrl?: string
}>()

const config = useRuntimeConfig()
const appName = computed(() => config.public.appName ?? 'Waaseyaa')
</script>

<template>
  <div class="auth-brand" aria-hidden="true">
    <img
      v-if="props.logoUrl"
      :src="props.logoUrl"
      :alt="appName"
      class="auth-brand-logo"
    />
    <h1 class="auth-brand-title">{{ appName }}</h1>
    <p v-if="props.tagline" class="auth-brand-tagline">{{ props.tagline }}</p>
  </div>
</template>

<style scoped>
.auth-brand {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: var(--waaseyaa-auth-brand-bg);
  color: var(--waaseyaa-auth-brand-color);
}

.auth-brand-logo {
  width: var(--waaseyaa-auth-brand-logo-size);
  height: var(--waaseyaa-auth-brand-logo-size);
  object-fit: contain;
}

.auth-brand-title {
  font-size: 2rem;
  font-weight: 700;
}

.auth-brand-tagline {
  opacity: 0.85;
}

@media (max-width: 767px) {
  .auth-brand {
    flex: none;
    padding: 1.5rem;
  }

  .auth-brand-title {
    font-size: 1.5rem;
  }

  .auth-brand-tagline {
    font-size: 0.875rem;
  }
}
</style>

<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{
  error: NuxtError
}>()

const { t } = useI18n()

useHead({
  title: t('error_page_title'),
})

const message = computed(() =>
  props.error.statusCode === 404 ? t('error_not_found') : t('error_generic'),
)
</script>

<template>
  <div class="error-page">
    <div class="error-card">
      <div class="error-icon" aria-hidden="true">
        {{ error.statusCode === 404 ? '404' : '!' }}
      </div>
      <h1 class="error-title">{{ $t('error_page_title') }}</h1>
      <p class="error-message">{{ message }}</p>
      <NuxtLink to="/" class="error-back">
        {{ $t('error_page_back') }}
      </NuxtLink>
    </div>
  </div>
</template>

<style scoped>
.error-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: #f5f5f5;
}

.error-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  width: 100%;
  max-width: 400px;
  padding: 2.5rem 2rem;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
  text-align: center;
}

.error-icon {
  font-size: 2.5rem;
  font-weight: 700;
  color: #4f46e5;
  line-height: 1;
}

.error-title {
  margin: 0;
  font-size: 1.4rem;
  font-weight: 600;
  color: #111;
}

.error-message {
  margin: 0;
  font-size: 0.95rem;
  color: #555;
}

.error-back {
  display: inline-block;
  margin-top: 0.5rem;
  padding: 0.55rem 1.25rem;
  background: #4f46e5;
  color: #fff;
  border-radius: 4px;
  font-size: 0.95rem;
  text-decoration: none;
  transition: background 0.15s;
}

.error-back:hover {
  background: #4338ca;
}
</style>

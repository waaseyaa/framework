<script setup lang="ts">
definePageMeta({ layout: false })

const config = useRuntimeConfig()
const route = useRoute()
const { login } = useAuth()

const logoUrl = config.public.logoUrl as string | undefined
const authConfig = config.public.auth as Record<string, unknown> | undefined
const registrationMode = authConfig?.registration ?? 'admin'
const showRegister = registrationMode === 'open' || registrationMode === 'invite'

// Validate returnTo is a local path to prevent open redirect attacks
const rawReturnTo = (route.query.returnTo as string) || '/'
const returnTo = rawReturnTo.startsWith('/') && !rawReturnTo.startsWith('//') ? rawReturnTo : '/'

const error = ref<string>('')
const loading = ref<boolean>(false)
const hidePanel = ref<boolean>(false)

onMounted(() => {
  const value = getComputedStyle(document.documentElement)
    .getPropertyValue('--waaseyaa-auth-hide-brand-panel')
    .trim()
  if (value === '1') {
    hidePanel.value = true
  }
})

async function handleSubmit(credentials: { username: string; password: string }) {
  error.value = ''
  loading.value = true
  try {
    const result = await login(credentials.username, credentials.password)
    if (result.success) {
      await navigateTo(returnTo)
    } else {
      error.value = result.error ?? 'Login failed'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div :class="['auth-page', { minimal: hidePanel }]">
    <AuthBrandPanel v-if="!hidePanel" :logo-url="logoUrl" />
    <div class="auth-form-panel">
      <div>
        <AuthLoginForm :error="error" :loading="loading" @submit="handleSubmit" />
        <div class="auth-page-links">
          <NuxtLink v-if="showRegister" to="/register" class="auth-page-link">Create account</NuxtLink>
          <span v-else />
          <NuxtLink to="/forgot-password" class="auth-page-link">Forgot password?</NuxtLink>
        </div>
      </div>
    </div>
  </div>
</template>

<style>
@import '~/assets/auth.css';
</style>

<style scoped>
.auth-page {
  display: flex;
  min-height: 100vh;
  background: var(--waaseyaa-auth-page-bg);
  font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.auth-form-panel {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  background: var(--waaseyaa-auth-form-bg);
}

.auth-page.minimal .auth-form-panel {
  max-width: 420px;
  margin: 0 auto;
  background: var(--waaseyaa-auth-page-bg);
}

.auth-page-links {
  display: flex;
  justify-content: space-between;
  margin-top: 1rem;
  font-size: 0.875rem;
}

.auth-page-link {
  color: var(--waaseyaa-auth-btn-bg);
  text-decoration: none;
}

.auth-page-link:hover {
  text-decoration: underline;
}

@media (max-width: 767px) {
  .auth-page {
    flex-direction: column;
  }
}
</style>

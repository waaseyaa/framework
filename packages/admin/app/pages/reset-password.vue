<script setup lang="ts">
definePageMeta({ layout: false })

const config = useRuntimeConfig()
const route = useRoute()
const { resetPassword } = useAuth()

const logoUrl = config.public.logoUrl as string | undefined

const token = route.query.token as string | undefined

const error = ref<string>(token ? '' : 'No reset token provided. Please request a new password reset link.')
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

async function handleSubmit(payload: { password: string; confirmPassword: string }) {
  if (payload.password !== payload.confirmPassword) {
    error.value = 'Passwords do not match.'
    return
  }

  if (!token) {
    error.value = 'No reset token provided. Please request a new password reset link.'
    return
  }

  error.value = ''
  loading.value = true
  try {
    const result = await resetPassword(token, payload.password, payload.confirmPassword)
    if (result.ok) {
      await navigateTo('/login?message=password-reset')
    } else {
      error.value = result.error ?? 'Password reset failed.'
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
      <AuthResetPasswordForm
        :error="error"
        :loading="loading"
        @submit="handleSubmit"
      />
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

@media (max-width: 767px) {
  .auth-page {
    flex-direction: column;
  }
}
</style>

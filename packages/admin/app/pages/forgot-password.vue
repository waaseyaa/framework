<script setup lang="ts">
definePageMeta({ layout: false })

const config = useRuntimeConfig()
const { forgotPassword } = useAuth()

const logoUrl = config.public.logoUrl as string | undefined

const error = ref<string>('')
const success = ref<string>('')
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

async function handleSubmit(payload: { email: string }) {
  error.value = ''
  success.value = ''
  loading.value = true
  try {
    const result = await forgotPassword(payload.email)
    if (result.ok) {
      success.value =
        'If an account exists for that email, a password reset link has been sent. Please check your inbox.'
    } else {
      error.value = result.error ?? 'Request failed.'
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
      <AuthForgotPasswordForm
        :error="error"
        :success="success"
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

<script setup lang="ts">
definePageMeta({ layout: false })

const { login } = useAuth()
const router = useRouter()

const username = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

// Check if $admin is available and what auth strategy is configured
const nuxtApp = useNuxtApp()
const admin = (nuxtApp as any).$admin
const authStrategy = admin?.bootstrap?.auth?.strategy ?? 'embedded'

// For redirect strategy, send user to external login
if (import.meta.client && authStrategy === 'redirect' && admin?.auth) {
  const returnTo = (useRoute().query.returnTo as string) || '/'
  window.location.href = admin.auth.getLoginUrl(returnTo)
}

async function handleSubmit() {
  error.value = ''
  loading.value = true
  try {
    await login(username.value, password.value)
    await router.push('/')
  }
  catch {
    error.value = 'Invalid username or password.'
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <form v-if="authStrategy === 'embedded'" class="login-form" @submit.prevent="handleSubmit">
      <h1>Sign in</h1>

      <div v-if="error" class="login-error" role="alert">
        {{ error }}
      </div>

      <label for="username">Username</label>
      <input
        id="username"
        v-model="username"
        type="text"
        name="username"
        autocomplete="username"
        required
        :disabled="loading"
      >

      <label for="password">Password</label>
      <input
        id="password"
        v-model="password"
        type="password"
        name="password"
        autocomplete="current-password"
        required
        :disabled="loading"
      >

      <button type="submit" :disabled="loading">
        {{ loading ? 'Signing in\u2026' : 'Sign in' }}
      </button>
    </form>

    <div v-else class="login-form">
      <h1>Redirecting to login...</h1>
      <p>If you are not redirected automatically, <a :href="admin?.auth?.getLoginUrl?.('/') ?? '/login'">click here</a>.</p>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: #f5f5f5;
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  width: 100%;
  max-width: 360px;
  padding: 2rem;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
}

.login-form h1 {
  margin: 0 0 0.5rem;
  font-size: 1.5rem;
}

.login-error {
  padding: 0.5rem 0.75rem;
  background: #fde8e8;
  border: 1px solid #f5a0a0;
  border-radius: 4px;
  color: #c00;
  font-size: 0.9rem;
}

.login-form label {
  font-size: 0.9rem;
  font-weight: 500;
}

.login-form input {
  padding: 0.5rem 0.75rem;
  border: 1px solid #ccc;
  border-radius: 4px;
  font-size: 1rem;
}

.login-form input:focus {
  outline: 2px solid #4f46e5;
  outline-offset: 1px;
  border-color: transparent;
}

.login-form button {
  margin-top: 0.5rem;
  padding: 0.6rem;
  background: #4f46e5;
  color: #fff;
  border: none;
  border-radius: 4px;
  font-size: 1rem;
  cursor: pointer;
}

.login-form button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>

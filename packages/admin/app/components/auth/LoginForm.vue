<template>
  <form class="auth-form" novalidate @submit.prevent="handleSubmit">
    <div v-if="error" role="alert" class="auth-form-error">
      {{ error }}
    </div>

    <div class="auth-form-field">
      <label for="login-username">Username</label>
      <input
        id="login-username"
        v-model="username"
        type="text"
        autocomplete="username"
        required
        :disabled="loading"
      />
    </div>

    <div class="auth-form-field">
      <label for="login-password">Password</label>
      <input
        id="login-password"
        v-model="password"
        type="password"
        autocomplete="current-password"
        required
        :disabled="loading"
      />
    </div>

    <button type="submit" class="auth-form-btn" :disabled="loading">
      {{ loading ? 'Signing in...' : 'Sign in' }}
    </button>
  </form>
</template>

<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    error?: string
    loading?: boolean
  }>(),
  {
    error: undefined,
    loading: false,
  },
)

const emit = defineEmits<{
  submit: [payload: { username: string; password: string }]
}>()

const username = ref('')
const password = ref('')

function handleSubmit() {
  emit('submit', { username: username.value, password: password.value })
}
</script>

<style scoped>
.auth-form {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  max-width: 360px;
}

.auth-form-field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.auth-form-field label {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--waaseyaa-auth-form-color);
}

.auth-form-field input {
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--waaseyaa-auth-input-border);
  border-radius: var(--waaseyaa-auth-input-radius);
  color: var(--waaseyaa-auth-form-color);
  background: var(--waaseyaa-auth-form-bg);
  font-size: 1rem;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.auth-form-field input:focus {
  outline: none;
  border-color: var(--waaseyaa-auth-input-focus);
  box-shadow: 0 0 0 3px var(--waaseyaa-auth-input-focus-ring);
}

.auth-form-field input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.auth-form-error {
  padding: 0.625rem 0.875rem;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: var(--waaseyaa-auth-input-radius);
  color: #dc2626;
  font-size: 0.875rem;
}

.auth-form-btn {
  padding: 0.625rem 1rem;
  background: var(--waaseyaa-auth-btn-bg);
  color: var(--waaseyaa-auth-btn-color);
  border: none;
  border-radius: var(--waaseyaa-auth-btn-radius);
  font-size: 1rem;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s;
}

.auth-form-btn:hover:not(:disabled) {
  background: var(--waaseyaa-auth-btn-hover);
}

.auth-form-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>

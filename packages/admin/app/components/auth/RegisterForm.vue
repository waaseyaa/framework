<template>
  <form class="auth-form" novalidate @submit.prevent="handleSubmit">
    <div v-if="error" role="alert" class="auth-form-error">
      {{ error }}
    </div>

    <div class="auth-form-field">
      <label for="register-name">Name</label>
      <input
        id="register-name"
        v-model="name"
        type="text"
        autocomplete="name"
        required
        :disabled="loading"
      />
    </div>

    <div class="auth-form-field">
      <label for="register-email">Email</label>
      <input
        id="register-email"
        v-model="email"
        type="email"
        autocomplete="email"
        required
        :disabled="loading"
      />
    </div>

    <div class="auth-form-field">
      <label for="register-password">Password</label>
      <input
        id="register-password"
        v-model="password"
        type="password"
        autocomplete="new-password"
        required
        :disabled="loading"
      />
    </div>

    <div class="auth-form-field">
      <label for="register-confirm-password">Confirm password</label>
      <input
        id="register-confirm-password"
        v-model="confirmPassword"
        type="password"
        autocomplete="new-password"
        required
        :disabled="loading"
      />
    </div>

    <button type="submit" class="auth-form-btn" :disabled="loading">
      {{ loading ? 'Creating account...' : 'Create account' }}
    </button>

    <p class="auth-form-footer">
      Already have an account?
      <a href="/login" class="auth-form-link">Sign in</a>
    </p>
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
  submit: [payload: { name: string; email: string; password: string; confirmPassword: string }]
}>()

const name = ref('')
const email = ref('')
const password = ref('')
const confirmPassword = ref('')

function handleSubmit() {
  emit('submit', {
    name: name.value,
    email: email.value,
    password: password.value,
    confirmPassword: confirmPassword.value,
  })
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

.auth-form-footer {
  font-size: 0.875rem;
  color: var(--waaseyaa-auth-form-color);
  text-align: center;
  margin: 0;
}

.auth-form-link {
  color: var(--waaseyaa-auth-btn-bg);
  text-decoration: none;
}

.auth-form-link:hover {
  text-decoration: underline;
}
</style>

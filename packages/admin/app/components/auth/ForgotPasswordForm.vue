<template>
  <form class="auth-form" novalidate @submit.prevent="handleSubmit">
    <div v-if="error" role="alert" class="auth-form-error">
      {{ error }}
    </div>

    <div v-if="success" role="status" class="auth-form-success">
      {{ success }}
    </div>

    <template v-if="!success">
      <div class="auth-form-field">
        <label for="forgot-email">Email address</label>
        <input
          id="forgot-email"
          v-model="email"
          type="email"
          autocomplete="email"
          required
          :disabled="loading"
        />
      </div>

      <button type="submit" class="auth-form-btn" :disabled="loading">
        {{ loading ? 'Sending...' : 'Send reset link' }}
      </button>
    </template>

    <div class="auth-form-footer">
      <NuxtLink to="/login">Back to sign in</NuxtLink>
    </div>
  </form>
</template>

<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    error?: string
    success?: string
    loading?: boolean
  }>(),
  {
    error: undefined,
    success: undefined,
    loading: false,
  },
)

const emit = defineEmits<{
  submit: [payload: { email: string }]
}>()

const email = ref('')

function handleSubmit() {
  emit('submit', { email: email.value })
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

.auth-form-success {
  padding: 0.625rem 0.875rem;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: var(--waaseyaa-auth-input-radius);
  color: #16a34a;
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
  text-align: center;
  color: var(--waaseyaa-auth-form-color);
}

.auth-form-footer a {
  color: var(--waaseyaa-auth-btn-bg);
  text-decoration: none;
}

.auth-form-footer a:hover {
  text-decoration: underline;
}
</style>

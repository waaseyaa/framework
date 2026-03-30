<script setup lang="ts">
definePageMeta({ layout: false })

const route = useRoute()
const { verifyEmail, resendVerification, logout } = useAuth()

const token = computed(() => route.query.token as string | undefined)

// State for auto-verify mode
const verifying = ref(false)
const verified = ref(false)
const verifyError = ref<string>('')

// State for resend cooldown
const cooldown = ref(0)
let cooldownTimer: ReturnType<typeof setInterval> | null = null

// State for resend action
const resending = ref(false)
const resendError = ref<string>('')
const resendSuccess = ref(false)

function startCooldown() {
  cooldown.value = 60
  cooldownTimer = setInterval(() => {
    cooldown.value--
    if (cooldown.value <= 0 && cooldownTimer) {
      clearInterval(cooldownTimer)
      cooldownTimer = null
    }
  }, 1000)
}

async function handleResend() {
  resendError.value = ''
  resendSuccess.value = false
  resending.value = true
  try {
    const result = await resendVerification()
    if (result.ok) {
      resendSuccess.value = true
      startCooldown()
    } else {
      resendError.value = result.error ?? 'Failed to resend verification email.'
    }
  } finally {
    resending.value = false
  }
}

async function handleBackToSignIn() {
  await logout()
  await navigateTo('/login')
}

onMounted(async () => {
  if (token.value) {
    verifying.value = true
    const result = await verifyEmail(token.value)
    verifying.value = false
    if (result.ok) {
      verified.value = true
      setTimeout(() => navigateTo('/'), 2000)
    } else {
      verifyError.value = result.error ?? 'Email verification failed.'
    }
  }
})

onUnmounted(() => {
  if (cooldownTimer) clearInterval(cooldownTimer)
})
</script>

<template>
  <div class="verify-page">
    <div class="verify-card">
      <div class="verify-logo">
        <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <rect width="40" height="40" rx="8" fill="#0d4f4f" />
          <path d="M10 20h20M20 10v20" stroke="#14b8a6" stroke-width="2.5" stroke-linecap="round" />
        </svg>
      </div>

      <!-- Auto-verify mode -->
      <template v-if="token">
        <template v-if="verifying">
          <p class="verify-status">Verifying your email&hellip;</p>
        </template>
        <template v-else-if="verified">
          <div class="verify-success">
            <svg class="success-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <circle cx="12" cy="12" r="10" fill="#0d4f4f" />
              <path d="M7 12.5l3.5 3.5 6-7" stroke="#14b8a6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <h1 class="verify-heading">Email verified!</h1>
            <p class="verify-subtext">Redirecting you to the dashboard&hellip;</p>
          </div>
        </template>
        <template v-else>
          <h1 class="verify-heading">Verification failed</h1>
          <p class="verify-error">{{ verifyError }}</p>
          <button
            class="verify-btn"
            :disabled="resending || cooldown > 0"
            @click="handleResend"
          >
            <template v-if="resending">Sending&hellip;</template>
            <template v-else-if="cooldown > 0">Resend in {{ cooldown }}s</template>
            <template v-else>Resend verification email</template>
          </button>
          <p v-if="resendSuccess" class="verify-hint success">Verification email sent — check your inbox.</p>
          <p v-if="resendError" class="verify-error">{{ resendError }}</p>
          <button class="verify-link-btn" @click="handleBackToSignIn">Back to sign in</button>
        </template>
      </template>

      <!-- Check-your-email mode -->
      <template v-else>
        <div class="verify-envelope" aria-hidden="true">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="4" y="10" width="40" height="28" rx="3" stroke="#0d4f4f" stroke-width="2" fill="#f0fdfa" />
            <path d="M4 14l20 14L44 14" stroke="#0d4f4f" stroke-width="2" stroke-linejoin="round" />
          </svg>
        </div>
        <h1 class="verify-heading">Check your email</h1>
        <p class="verify-subtext">
          We sent a verification link to your email address.
          Click the link to verify your account.
        </p>
        <button
          class="verify-btn"
          :disabled="resending || cooldown > 0"
          @click="handleResend"
        >
          <template v-if="resending">Sending&hellip;</template>
          <template v-else-if="cooldown > 0">Resend in {{ cooldown }}s</template>
          <template v-else>Resend verification email</template>
        </button>
        <p v-if="resendSuccess" class="verify-hint success">Verification email sent — check your inbox.</p>
        <p v-if="resendError" class="verify-error">{{ resendError }}</p>
        <button class="verify-link-btn" @click="handleBackToSignIn">Back to sign in</button>
      </template>
    </div>
  </div>
</template>

<style>
@import '~/assets/auth.css';
</style>

<style scoped>
.verify-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--waaseyaa-auth-page-bg, #f8fafc);
  font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  padding: 2rem;
}

.verify-card {
  background: var(--waaseyaa-auth-form-bg, #ffffff);
  border-radius: 12px;
  box-shadow: 0 4px 24px rgb(0 0 0 / 0.08);
  padding: 2.5rem 2rem;
  width: 100%;
  max-width: 400px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.verify-logo {
  margin-bottom: 0.25rem;
}

.verify-envelope {
  margin: 0.25rem 0;
}

.verify-heading {
  font-size: 1.375rem;
  font-weight: 700;
  color: #0d4f4f;
  margin: 0;
}

.verify-subtext {
  font-size: 0.9375rem;
  color: #64748b;
  line-height: 1.6;
  margin: 0;
}

.verify-status {
  font-size: 1rem;
  color: #64748b;
  margin: 0.5rem 0;
}

.verify-error {
  font-size: 0.875rem;
  color: #dc2626;
  margin: 0;
}

.verify-hint {
  font-size: 0.875rem;
  color: #64748b;
  margin: 0;
}

.verify-hint.success {
  color: #0d4f4f;
}

.verify-success {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
}

.success-icon {
  width: 48px;
  height: 48px;
}

.verify-btn {
  width: 100%;
  padding: 0.625rem 1.25rem;
  background: #0d4f4f;
  color: #ffffff;
  border: none;
  border-radius: 6px;
  font-size: 0.9375rem;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s;
}

.verify-btn:hover:not(:disabled) {
  background: #0f766e;
}

.verify-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.verify-link-btn {
  background: none;
  border: none;
  color: #0f766e;
  font-size: 0.875rem;
  cursor: pointer;
  padding: 0;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.verify-link-btn:hover {
  color: #0d4f4f;
}
</style>

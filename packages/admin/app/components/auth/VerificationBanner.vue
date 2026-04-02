<script setup lang="ts">
const { t } = useLanguage()
const { currentUser, resendVerification } = useAuth()

const dismissed = ref(false)
const resending = ref(false)
const resendSuccess = ref(false)
const resendError = ref<string>('')

const storageKey = computed(() =>
  currentUser.value ? `waaseyaa.verify.dismissed.${currentUser.value.id}` : null,
)

const visible = computed(
  () =>
    currentUser.value !== null &&
    currentUser.value.emailVerified !== true &&
    !dismissed.value,
)

onMounted(() => {
  if (storageKey.value && localStorage.getItem(storageKey.value) === '1') {
    dismissed.value = true
  }
})

watch(
  () => currentUser.value?.emailVerified,
  (verified) => {
    if (verified) dismissed.value = true
  },
)

function dismiss() {
  dismissed.value = true
  if (storageKey.value) {
    localStorage.setItem(storageKey.value, '1')
  }
}

async function handleResend() {
  resendError.value = ''
  resendSuccess.value = false
  resending.value = true
  try {
    const result = await resendVerification()
    if (result.ok) {
      resendSuccess.value = true
    } else {
      resendError.value = result.error ?? t('error_resend_verification')
    }
  } finally {
    resending.value = false
  }
}
</script>

<template>
  <div v-if="visible" class="verification-banner" role="alert">
    <div class="banner-content">
      <svg class="banner-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path
          fill-rule="evenodd"
          d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
          clip-rule="evenodd"
        />
      </svg>
      <span class="banner-text">{{ t('verify_email_prompt') }}</span>
      <span v-if="resendSuccess" class="banner-sent">{{ t('verify_email_sent') }}</span>
      <span v-if="resendError" class="banner-error">{{ resendError }}</span>
      <button
        class="banner-resend"
        :disabled="resending"
        @click="handleResend"
      >
        {{ resending ? t('sending') : t('resend_verification') }}
      </button>
    </div>
    <button class="banner-dismiss" :aria-label="t('dismiss')" @click="dismiss">
      <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16" aria-hidden="true">
        <path
          d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
        />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.verification-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.625rem 1rem;
  background: #fef9c3;
  border-bottom: 1px solid #fde047;
  font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  font-size: 0.875rem;
}

.banner-content {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.banner-icon {
  width: 16px;
  height: 16px;
  color: #a16207;
  flex-shrink: 0;
}

.banner-text {
  color: #713f12;
  font-weight: 500;
}

.banner-sent {
  color: #0d4f4f;
  font-weight: 500;
}

.banner-error {
  color: #dc2626;
}

.banner-resend {
  background: none;
  border: 1px solid #a16207;
  border-radius: 4px;
  color: #713f12;
  font-size: 0.8125rem;
  padding: 0.1875rem 0.625rem;
  cursor: pointer;
  transition: background 0.15s;
}

.banner-resend:hover:not(:disabled) {
  background: #fef08a;
}

.banner-resend:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.banner-dismiss {
  background: none;
  border: none;
  color: #a16207;
  cursor: pointer;
  padding: 0.25rem;
  display: flex;
  align-items: center;
  flex-shrink: 0;
  border-radius: 4px;
  transition: background 0.15s;
}

.banner-dismiss:hover {
  background: #fde047;
}
</style>

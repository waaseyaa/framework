<script setup lang="ts">
import type { AdminRuntime } from '~/contracts'

definePageMeta({ layout: false })

const nuxtApp = useNuxtApp()
const admin = (nuxtApp as any).$admin as AdminRuntime | null

// Redirect to the server-side login page
if (import.meta.client && admin?.auth) {
  const returnTo = (useRoute().query.returnTo as string) || '/'
  window.location.href = admin.auth.getLoginUrl(returnTo)
}
</script>

<template>
  <div class="login-page">
    <div class="login-form">
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
</style>

# Auth System Phase 1: Login — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a framework-level, brandable Split Panel login page that works out of the box on any Waaseyaa app, with a rewritten useAuth() composable, secure session cookies, rate limiting, scaffold CLI, and full test coverage.

**Architecture:** The admin SPA's Nuxt plugin detects 401 from `/_surface/session` and navigates to `/login`. The login page (Split Panel layout, `layout: false`) posts credentials to `/api/auth/login` via the rewritten `useAuth().login()`. All theming via CSS custom properties. Override by placing `app/pages/login.vue` in the consuming app. Eject via `bin/waaseyaa scaffold:auth`.

**Tech Stack:** Vue 3 + Nuxt 3 (SPA), PHP 8.4 (backend), Vitest (unit), Playwright (E2E), Symfony Console (CLI)

**Design doc:** `docs/superpowers/specs/2026-03-29-auth-system-design.md`

---

## File Map

### New files

| File | Responsibility |
|------|---------------|
| `packages/admin/app/assets/auth.css` | CSS custom property defaults for all auth screens |
| `packages/admin/app/components/auth/BrandPanel.vue` | Left panel: gradient, logo, app name, tagline |
| `packages/admin/app/components/auth/LoginForm.vue` | Form fields, submit handler, error display |
| `packages/admin/app/pages/login.vue` | Split Panel page composing BrandPanel + LoginForm |
| `packages/admin/tests/components/auth/LoginForm.spec.ts` | Vitest: LoginForm emit and render tests |
| `packages/admin/tests/components/auth/BrandPanel.spec.ts` | Vitest: BrandPanel render tests |
| `packages/admin/tests/composables/useAuth.spec.ts` | Vitest: useAuth login/logout/checkAuth tests |
| `packages/admin/e2e/login.spec.ts` | Playwright: login flow E2E tests |
| `packages/admin/e2e/fixtures/auth.ts` | Playwright: auth route mock helpers |
| `packages/cli/src/Command/ScaffoldAuthCommand.php` | CLI: scaffold:auth command |
| `packages/cli/tests/Unit/ScaffoldAuthCommandTest.php` | PHPUnit: scaffold:auth tests |

### Modified files

| File | Change |
|------|--------|
| `packages/admin/app/composables/useAuth.ts` | Rewrite login() to call API, add LoginResult type |
| `packages/admin/app/plugins/admin.ts` | Fix 401 → `navigateTo('/login')` instead of `window.location.href` |
| `packages/foundation/src/Http/ControllerDispatcher.php:690-723` | Add rate limiting to auth.login handler |

---

## Task 1: Create auth.css with CSS custom property defaults

**Issue:** #762
**Files:**
- Create: `packages/admin/app/assets/auth.css`

- [ ] **Step 1: Create the CSS file with all token defaults**

```css
/* packages/admin/app/assets/auth.css */

/* Auth screen theming tokens — override these in your app stylesheet */
:root {
  /* Brand panel */
  --waaseyaa-auth-brand-bg: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
  --waaseyaa-auth-brand-color: #ffffff;
  --waaseyaa-auth-brand-logo: none;
  --waaseyaa-auth-brand-logo-size: 48px;

  /* Form panel */
  --waaseyaa-auth-form-bg: #ffffff;
  --waaseyaa-auth-form-color: #1e293b;
  --waaseyaa-auth-form-muted: #64748b;

  /* Inputs */
  --waaseyaa-auth-input-border: #d1d5db;
  --waaseyaa-auth-input-focus: #2563eb;
  --waaseyaa-auth-input-focus-ring: rgba(37, 99, 235, 0.15);
  --waaseyaa-auth-input-radius: 6px;

  /* Button */
  --waaseyaa-auth-btn-bg: #2563eb;
  --waaseyaa-auth-btn-hover: #1d4ed8;
  --waaseyaa-auth-btn-color: #ffffff;
  --waaseyaa-auth-btn-radius: 6px;

  /* Page */
  --waaseyaa-auth-page-bg: #f1f5f9;
  --waaseyaa-auth-card-radius: 12px;
  --waaseyaa-auth-card-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);

  /* Density toggle: set to 1 to hide brand panel (Centered Minimal) */
  --waaseyaa-auth-hide-brand-panel: 0;
}
```

- [ ] **Step 2: Verify file exists**

Run: `cat packages/admin/app/assets/auth.css | head -5`
Expected: Shows the CSS comment and `:root {`

- [ ] **Step 3: Commit**

```bash
git add packages/admin/app/assets/auth.css
git commit -m "feat(admin): add auth.css with CSS custom property defaults (#762)"
```

---

## Task 2: Create BrandPanel component

**Issue:** #762
**Files:**
- Create: `packages/admin/app/components/auth/BrandPanel.vue`
- Create: `packages/admin/tests/components/auth/BrandPanel.spec.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// packages/admin/tests/components/auth/BrandPanel.spec.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import BrandPanel from '~/components/auth/BrandPanel.vue'

describe('BrandPanel', () => {
  it('renders app name from runtime config', async () => {
    const wrapper = await mountSuspended(BrandPanel)
    expect(wrapper.text()).toContain('Waaseyaa')
  })

  it('renders tagline when provided', async () => {
    const wrapper = await mountSuspended(BrandPanel, {
      props: { tagline: 'Build. Publish. Scale.' },
    })
    expect(wrapper.text()).toContain('Build. Publish. Scale.')
  })

  it('renders logo img when logoUrl is configured', async () => {
    const wrapper = await mountSuspended(BrandPanel, {
      props: { logoUrl: '/logo.svg' },
    })
    const img = wrapper.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe('/logo.svg')
  })

  it('does not render img when no logoUrl', async () => {
    const wrapper = await mountSuspended(BrandPanel)
    expect(wrapper.find('img').exists()).toBe(false)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/admin && npx vitest run tests/components/auth/BrandPanel.spec.ts`
Expected: FAIL — cannot resolve `~/components/auth/BrandPanel.vue`

- [ ] **Step 3: Write the BrandPanel component**

```vue
<!-- packages/admin/app/components/auth/BrandPanel.vue -->
<script setup lang="ts">
defineProps<{
  tagline?: string
  logoUrl?: string
}>()

const config = useRuntimeConfig()
const appName = (config.public.appName as string) || 'Waaseyaa'
</script>

<template>
  <div class="auth-brand" aria-hidden="true">
    <div class="auth-brand-content">
      <img
        v-if="logoUrl"
        :src="logoUrl"
        :alt="appName"
        class="auth-brand-logo"
      />
      <h1 class="auth-brand-title">{{ appName }}</h1>
      <p v-if="tagline" class="auth-brand-tagline">{{ tagline }}</p>
    </div>
  </div>
</template>

<style scoped>
.auth-brand {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--waaseyaa-auth-brand-bg);
  color: var(--waaseyaa-auth-brand-color);
  padding: 2rem;
}

.auth-brand-content {
  text-align: center;
}

.auth-brand-logo {
  width: var(--waaseyaa-auth-brand-logo-size);
  height: var(--waaseyaa-auth-brand-logo-size);
  margin-bottom: 1rem;
  object-fit: contain;
}

.auth-brand-title {
  font-size: 2rem;
  font-weight: 700;
  margin: 0;
}

.auth-brand-tagline {
  margin-top: 0.5rem;
  font-size: 1rem;
  opacity: 0.85;
}

@media (max-width: 767px) {
  .auth-brand {
    flex: none;
    padding: 1.5rem;
  }

  .auth-brand-title {
    font-size: 1.25rem;
  }

  .auth-brand-tagline {
    font-size: 0.875rem;
  }
}
</style>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/admin && npx vitest run tests/components/auth/BrandPanel.spec.ts`
Expected: 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/components/auth/BrandPanel.vue packages/admin/tests/components/auth/BrandPanel.spec.ts
git commit -m "feat(admin): add BrandPanel component with CSS variable theming (#762)"
```

---

## Task 3: Create LoginForm component

**Issue:** #762
**Files:**
- Create: `packages/admin/app/components/auth/LoginForm.vue`
- Create: `packages/admin/tests/components/auth/LoginForm.spec.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// packages/admin/tests/components/auth/LoginForm.spec.ts
import { describe, it, expect } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import LoginForm from '~/components/auth/LoginForm.vue'

describe('LoginForm', () => {
  it('renders username and password fields', async () => {
    const wrapper = await mountSuspended(LoginForm)
    expect(wrapper.find('#login-username').exists()).toBe(true)
    expect(wrapper.find('#login-password').exists()).toBe(true)
  })

  it('renders labels for accessibility', async () => {
    const wrapper = await mountSuspended(LoginForm)
    const labels = wrapper.findAll('label')
    expect(labels.length).toBeGreaterThanOrEqual(2)
  })

  it('emits submit with username and password', async () => {
    const wrapper = await mountSuspended(LoginForm)
    await wrapper.find('#login-username').setValue('admin')
    await wrapper.find('#login-password').setValue('secret')
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('submit')).toBeTruthy()
    expect(wrapper.emitted('submit')![0]).toEqual([{ username: 'admin', password: 'secret' }])
  })

  it('displays error message when provided', async () => {
    const wrapper = await mountSuspended(LoginForm, {
      props: { error: 'Invalid credentials.' },
    })
    expect(wrapper.text()).toContain('Invalid credentials.')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('disables button when loading', async () => {
    const wrapper = await mountSuspended(LoginForm, {
      props: { loading: true },
    })
    const btn = wrapper.find('button[type="submit"]')
    expect(btn.attributes('disabled')).toBeDefined()
    expect(btn.text()).toContain('Signing in')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/admin && npx vitest run tests/components/auth/LoginForm.spec.ts`
Expected: FAIL — cannot resolve `~/components/auth/LoginForm.vue`

- [ ] **Step 3: Write the LoginForm component**

```vue
<!-- packages/admin/app/components/auth/LoginForm.vue -->
<script setup lang="ts">
const props = defineProps<{
  error?: string
  loading?: boolean
}>()

const emit = defineEmits<{
  submit: [payload: { username: string; password: string }]
}>()

const username = ref('')
const password = ref('')

function handleSubmit() {
  emit('submit', { username: username.value, password: password.value })
}
</script>

<template>
  <form class="auth-form" @submit.prevent="handleSubmit" novalidate>
    <div v-if="props.error" class="auth-form-error" role="alert">
      {{ props.error }}
    </div>

    <div class="auth-form-field">
      <label for="login-username">Username or email</label>
      <input
        id="login-username"
        v-model="username"
        type="text"
        autocomplete="username"
        required
        :disabled="props.loading"
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
        :disabled="props.loading"
      />
    </div>

    <button type="submit" class="auth-form-btn" :disabled="props.loading">
      {{ props.loading ? 'Signing in...' : 'Sign in' }}
    </button>
  </form>
</template>

<style scoped>
.auth-form {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  width: 100%;
  max-width: 360px;
}

.auth-form-error {
  padding: 0.75rem 1rem;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: var(--waaseyaa-auth-input-radius);
  color: #dc2626;
  font-size: 0.875rem;
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
  padding: 0.625rem 0.75rem;
  border: 1px solid var(--waaseyaa-auth-input-border);
  border-radius: var(--waaseyaa-auth-input-radius);
  font-size: 0.9375rem;
  color: var(--waaseyaa-auth-form-color);
  background: var(--waaseyaa-auth-form-bg);
  transition: border-color 0.15s, box-shadow 0.15s;
}

.auth-form-field input:focus {
  outline: none;
  border-color: var(--waaseyaa-auth-input-focus);
  box-shadow: 0 0 0 2px var(--waaseyaa-auth-input-focus-ring);
}

.auth-form-field input:disabled {
  opacity: 0.6;
}

.auth-form-btn {
  padding: 0.7rem;
  background: var(--waaseyaa-auth-btn-bg);
  color: var(--waaseyaa-auth-btn-color);
  border: none;
  border-radius: var(--waaseyaa-auth-btn-radius);
  font-size: 0.9375rem;
  font-weight: 600;
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/admin && npx vitest run tests/components/auth/LoginForm.spec.ts`
Expected: 5 tests PASS

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/components/auth/LoginForm.vue packages/admin/tests/components/auth/LoginForm.spec.ts
git commit -m "feat(admin): add LoginForm component with a11y and error display (#762)"
```

---

## Task 4: Rewrite useAuth() composable

**Issue:** #761
**Files:**
- Modify: `packages/admin/app/composables/useAuth.ts`
- Create: `packages/admin/tests/composables/useAuth.spec.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// packages/admin/tests/composables/useAuth.spec.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { defineComponent } from 'vue'

// Helper to use composable in a mounted component context
function withSetup<T>(composable: () => T): { result: T; wrapper: any } {
  let result!: T
  const Comp = defineComponent({
    setup() {
      result = composable()
      return {}
    },
    template: '<div />',
  })
  const wrapper = mountSuspended(Comp)
  return { result, wrapper }
}

describe('useAuth', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('login() returns success with account on 200', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      data: { id: '1', name: 'admin', email: 'admin@example.com', roles: ['admin'] },
    })
    vi.stubGlobal('$fetch', mockFetch)

    const { useAuth } = await import('~/composables/useAuth')
    const { result } = withSetup(() => useAuth())
    const loginResult = await result.login('admin', 'secret')

    expect(loginResult.success).toBe(true)
    expect(loginResult.account?.name).toBe('admin')
    expect(result.currentUser.value?.name).toBe('admin')
    expect(result.isAuthenticated.value).toBe(true)
  })

  it('login() returns failure on 401', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      errors: [{ status: '401', title: 'Unauthorized', detail: 'Invalid credentials.' }],
    })
    vi.stubGlobal('$fetch', mockFetch)

    const { useAuth } = await import('~/composables/useAuth')
    const { result } = withSetup(() => useAuth())
    const loginResult = await result.login('bad', 'creds')

    expect(loginResult.success).toBe(false)
    expect(loginResult.error).toBe('Invalid credentials.')
    expect(result.currentUser.value).toBeNull()
  })

  it('login() returns failure on network error', async () => {
    const mockFetch = vi.fn().mockRejectedValue(new Error('Network error'))
    vi.stubGlobal('$fetch', mockFetch)

    const { useAuth } = await import('~/composables/useAuth')
    const { result } = withSetup(() => useAuth())
    const loginResult = await result.login('admin', 'secret')

    expect(loginResult.success).toBe(false)
    expect(loginResult.error).toBe('Unable to reach the server. Please try again.')
  })

  it('logout() clears state', async () => {
    const mockFetch = vi.fn().mockResolvedValue({})
    vi.stubGlobal('$fetch', mockFetch)

    const { useAuth } = await import('~/composables/useAuth')
    const { result } = withSetup(() => useAuth())
    result.currentUser.value = { id: '1', name: 'admin', email: '', roles: ['admin'] }

    await result.logout()

    expect(result.currentUser.value).toBeNull()
    expect(result.isAuthenticated.value).toBe(false)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/admin && npx vitest run tests/composables/useAuth.spec.ts`
Expected: FAIL — login() returns void, not LoginResult

- [ ] **Step 3: Rewrite useAuth.ts**

Replace the full contents of `packages/admin/app/composables/useAuth.ts`:

```typescript
// packages/admin/app/composables/useAuth.ts
import type { AdminAccount } from '../contracts/auth'

export type { AdminAccount }

export interface LoginResult {
  success: boolean
  error?: string
  account?: AdminAccount
}

const STATE_KEY = 'waaseyaa.auth.user'
const CHECKED_KEY = 'waaseyaa.auth.checked'

export function useAuth() {
  const currentUser = useState<AdminAccount | null>(STATE_KEY, () => null)
  const authChecked = useState<boolean>(CHECKED_KEY, () => false)
  const isAuthenticated = computed(() => currentUser.value !== null)

  async function checkAuth(): Promise<void> {
    if (authChecked.value) return
    try {
      const res = await $fetch<{ data?: AdminAccount }>('/api/user/me', {
        credentials: 'include',
        ignoreResponseError: true,
      })
      currentUser.value = res?.data?.id ? (res.data as AdminAccount) : null
    } catch {
      currentUser.value = null
    }
    authChecked.value = true
  }

  async function login(username: string, password: string): Promise<LoginResult> {
    try {
      const res = await $fetch<{
        data?: { id: string; name: string; email: string; roles: string[] }
        errors?: Array<{ status: string; title: string; detail?: string }>
      }>('/api/auth/login', {
        method: 'POST',
        body: { username, password },
        credentials: 'include',
        ignoreResponseError: true,
      })

      if (res?.data?.id) {
        const account: AdminAccount = {
          id: String(res.data.id),
          name: res.data.name,
          email: res.data.email,
          roles: res.data.roles,
        }
        currentUser.value = account
        authChecked.value = true
        return { success: true, account }
      }

      const detail = res?.errors?.[0]?.detail || 'Invalid username or password.'
      return { success: false, error: detail }
    } catch {
      return { success: false, error: 'Unable to reach the server. Please try again.' }
    }
  }

  async function logout(): Promise<void> {
    try {
      await $fetch('/api/auth/logout', {
        method: 'POST',
        credentials: 'include',
        ignoreResponseError: true,
      })
    } catch {
      // Best-effort — clear local state regardless
    }
    currentUser.value = null
    authChecked.value = false
  }

  return { currentUser, isAuthenticated, checkAuth, login, logout }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/admin && npx vitest run tests/composables/useAuth.spec.ts`
Expected: 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add packages/admin/app/composables/useAuth.ts packages/admin/tests/composables/useAuth.spec.ts
git commit -m "feat(admin): rewrite useAuth() to call /api/auth/login (#761)"
```

---

## Task 5: Create login.vue page (Split Panel)

**Issue:** #762
**Files:**
- Create: `packages/admin/app/pages/login.vue`

- [ ] **Step 1: Create the login page**

```vue
<!-- packages/admin/app/pages/login.vue -->
<script setup lang="ts">
import '~/assets/auth.css'

definePageMeta({ layout: false })

const config = useRuntimeConfig()
const logoUrl = (config.public.logoUrl as string) || ''
const route = useRoute()

const error = ref('')
const loading = ref(false)
const hidePanel = ref(false)

onMounted(() => {
  // Read density toggle from CSS custom property
  const value = getComputedStyle(document.documentElement)
    .getPropertyValue('--waaseyaa-auth-hide-brand-panel')
    .trim()
  hidePanel.value = value === '1'
})

const { login } = useAuth()

async function handleSubmit(payload: { username: string; password: string }) {
  error.value = ''
  loading.value = true

  const result = await login(payload.username, payload.password)

  if (result.success) {
    const returnTo = (route.query.returnTo as string) || '/'
    await navigateTo(returnTo)
  } else {
    error.value = result.error || 'Login failed.'
    loading.value = false
  }
}
</script>

<template>
  <div :class="['auth-page', { minimal: hidePanel }]">
    <AuthBrandPanel v-if="!hidePanel" :logo-url="logoUrl" />
    <div class="auth-form-panel">
      <AuthLoginForm :error="error" :loading="loading" @submit="handleSubmit" />
    </div>
  </div>
</template>

<style scoped>
.auth-page {
  display: flex;
  min-height: 100vh;
  background: var(--waaseyaa-auth-page-bg);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.auth-form-panel {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  background: var(--waaseyaa-auth-form-bg);
}

/* Centered Minimal density */
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
```

- [ ] **Step 2: Verify build compiles**

Run: `cd packages/admin && npx nuxi typecheck 2>&1 | tail -5`
Expected: No type errors related to login.vue, LoginForm, or BrandPanel

- [ ] **Step 3: Commit**

```bash
git add packages/admin/app/pages/login.vue
git commit -m "feat(admin): add Split Panel login page (#762)"
```

---

## Task 6: Fix admin.ts plugin 401 handling

**Issue:** #760
**Files:**
- Modify: `packages/admin/app/plugins/admin.ts:57-62,73-78`

- [ ] **Step 1: Fix the 401 redirect in admin.ts**

In `packages/admin/app/plugins/admin.ts`, replace the `window.location.href` calls with `navigateTo`:

Replace lines 57-62 (the 401 handler):
```typescript
    } else if (sessionRes && !sessionRes.ok && sessionRes.error?.status === 401) {
      if (import.meta.client) {
        window.location.href = loginUrl
      }
      return { provide: { admin: null } }
    }
```
With:
```typescript
    } else if (sessionRes && !sessionRes.ok && sessionRes.error?.status === 401) {
      if (import.meta.client) {
        navigateTo('/login', { replace: true })
      }
      return { provide: { admin: null } }
    }
```

Replace lines 73-78 (the null session fallback):
```typescript
  if (!surfaceSession || !surfaceCatalog) {
    if (import.meta.client) {
      window.location.href = loginUrl
    }
    return { provide: { admin: null } }
  }
```
With:
```typescript
  if (!surfaceSession || !surfaceCatalog) {
    if (import.meta.client) {
      navigateTo('/login', { replace: true })
    }
    return { provide: { admin: null } }
  }
```

Also remove the unused `loginUrl` variable from line 34:
```typescript
  const loginUrl = `${baseUrl}/login`
```

- [ ] **Step 2: Verify build compiles**

Run: `cd packages/admin && npx nuxi typecheck 2>&1 | tail -5`
Expected: No type errors

- [ ] **Step 3: Commit**

```bash
git add packages/admin/app/plugins/admin.ts
git commit -m "fix(admin): navigate to /login on 401 instead of window.location.href (#760)"
```

---

## Task 7: Add rate limiting to auth.login endpoint

**Issue:** #763
**Files:**
- Modify: `packages/foundation/src/Http/ControllerDispatcher.php:690-723`

- [ ] **Step 1: Add RateLimiter to ControllerDispatcher constructor or resolve it**

The `RateLimiter` in `packages/auth/src/RateLimiter.php` is an in-memory rate limiter. For the PHP built-in server (single process), this works per-process. For production (PHP-FPM), a persistent store would be needed — but Phase 1 uses the in-memory version.

In `ControllerDispatcher`, before the `auth.login` match (around line 690), add rate limiting:

```php
$controller === 'auth.login' => (function () use ($body): never {
    // Rate limiting: 5 attempts per IP per 60 seconds
    static $rateLimiter = null;
    $rateLimiter ??= new \Waaseyaa\Auth\RateLimiter();
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rateLimitKey = 'login:' . $clientIp;

    if ($rateLimiter->tooManyAttempts($rateLimitKey, 5)) {
        ResponseSender::json(429, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [['status' => '429', 'title' => 'Too Many Requests', 'detail' => 'Too many login attempts. Please try again later.']],
        ], ['Retry-After' => '60']);
    }

    $safeBody = $body ?? [];
    $username = is_string($safeBody['username'] ?? null) ? trim((string) $safeBody['username']) : '';
    $password = is_string($safeBody['password'] ?? null) ? (string) $safeBody['password'] : '';

    if ($username === '' || $password === '') {
        ResponseSender::json(400, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'username and password are required.']],
        ]);
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $authController = new AuthController();
    $user = $authController->findUserByName($userStorage, $username);

    if ($user === null || !$user->isActive() || !$user->checkPassword($password)) {
        $rateLimiter->hit($rateLimitKey, 60);
        ResponseSender::json(401, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [['status' => '401', 'title' => 'Unauthorized', 'detail' => 'Invalid credentials.']],
        ]);
    }

    // Successful login — clear rate limit for this IP
    $rateLimiter->clear($rateLimitKey);
    $_SESSION['waaseyaa_uid'] = $user->id();
    ResponseSender::json(200, [
        'jsonapi' => ['version' => '1.1'],
        'data' => [
            'id' => $user->id(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ],
    ]);
})(),
```

- [ ] **Step 2: Verify ResponseSender::json accepts headers parameter**

Check if `ResponseSender::json()` supports a third `$headers` parameter. If not, add the `Retry-After` header via `header('Retry-After: 60')` before the `ResponseSender::json()` call.

- [ ] **Step 3: Run existing tests**

Run: `./vendor/bin/phpunit --testsuite Unit --filter Auth`
Expected: Existing auth tests still pass

- [ ] **Step 4: Commit**

```bash
git add packages/foundation/src/Http/ControllerDispatcher.php
git commit -m "feat(auth): apply rate limiting to POST /api/auth/login (#763)"
```

---

## Task 8: Fix session cookie Secure flag for proxy TLS termination

**Issue:** #764
**Files:**
- Modify: `packages/user/src/Session/NativeSession.php:25-29`
- Modify: `packages/user/tests/Unit/Session/NativeSessionTest.php`

- [ ] **Step 1: Write the failing test**

Add to the existing test file `packages/user/tests/Unit/Session/NativeSessionTest.php`:

```php
#[Test]
public function secure_flag_respects_x_forwarded_proto(): void
{
    // Simulate proxy TLS termination
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    unset($_SERVER['HTTPS']);

    $session = new NativeSession();
    // We can't easily test session_set_cookie_params in a unit test,
    // but we can test the helper method that determines the secure flag.
    // Add a public method isSecureConnection() for testability.
    self::assertTrue($session->isSecureConnection());

    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
}

#[Test]
public function secure_flag_false_on_plain_http(): void
{
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

    $session = new NativeSession();
    self::assertFalse($session->isSecureConnection());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/Session/NativeSessionTest.php --filter x_forwarded`
Expected: FAIL — method `isSecureConnection()` does not exist

- [ ] **Step 3: Update NativeSession to check X-Forwarded-Proto**

In `packages/user/src/Session/NativeSession.php`, replace lines 25-29:

```php
        session_set_cookie_params([
            'httponly' => true,
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);
```

With:

```php
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $this->isSecureConnection(),
            'samesite' => 'Lax',
        ]);
```

And add the helper method:

```php
    /**
     * Determine if the current connection is secure (HTTPS or behind TLS-terminating proxy).
     */
    public function isSecureConnection(): bool
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Trust X-Forwarded-Proto from reverse proxy (Caddy, nginx, etc.)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }

        return false;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/Session/NativeSessionTest.php`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add packages/user/src/Session/NativeSession.php packages/user/tests/Unit/Session/NativeSessionTest.php
git commit -m "fix(user): session Secure flag respects X-Forwarded-Proto for proxy TLS (#764)"
```

---

## Task 9: E2E test fixtures for auth routes

**Issue:** #766
**Files:**
- Create: `packages/admin/e2e/fixtures/auth.ts`

- [ ] **Step 1: Create auth route mock helpers**

```typescript
// packages/admin/e2e/fixtures/auth.ts
import type { Page } from '@playwright/test'

const DEV_ADMIN_ID = String(Number.MAX_SAFE_INTEGER)

/**
 * Mock the surface session endpoint to return 401 (unauthenticated).
 */
export async function mockUnauthenticatedSession(page: Page) {
  await page.route('**/admin/surface/session', (route) =>
    route.fulfill({
      status: 200,
      json: { ok: false, error: { status: 401, title: 'Unauthorized' } },
    }),
  )
}

/**
 * Mock the login endpoint to succeed with dev-admin credentials.
 */
export async function mockLoginSuccess(page: Page, username = 'dev-admin', password = 'password') {
  await page.route('**/api/auth/login', async (route) => {
    const body = route.request().postDataJSON()
    if (body?.username === username && body?.password === password) {
      await route.fulfill({
        json: {
          jsonapi: { version: '1.1' },
          data: { id: DEV_ADMIN_ID, name: username, email: `${username}@example.com`, roles: ['admin'] },
        },
      })
    } else {
      await route.fulfill({
        status: 401,
        json: {
          jsonapi: { version: '1.1' },
          errors: [{ status: '401', title: 'Unauthorized', detail: 'Invalid credentials.' }],
        },
      })
    }
  })
}

/**
 * Mock the login endpoint to always return 429 (rate limited).
 */
export async function mockLoginRateLimited(page: Page) {
  await page.route('**/api/auth/login', (route) =>
    route.fulfill({
      status: 429,
      headers: { 'Retry-After': '60' },
      json: {
        jsonapi: { version: '1.1' },
        errors: [{ status: '429', title: 'Too Many Requests', detail: 'Too many login attempts. Please try again later.' }],
      },
    }),
  )
}

/**
 * Mock the logout endpoint.
 */
export async function mockLogout(page: Page) {
  await page.route('**/api/auth/logout', (route) =>
    route.fulfill({
      json: { jsonapi: { version: '1.1' }, meta: { message: 'Logged out.' } },
    }),
  )
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/admin/e2e/fixtures/auth.ts
git commit -m "test(admin): add E2E auth route mock fixtures (#766)"
```

---

## Task 10: Playwright E2E login tests

**Issue:** #766
**Files:**
- Create: `packages/admin/e2e/login.spec.ts`

- [ ] **Step 1: Write the E2E test suite**

```typescript
// packages/admin/e2e/login.spec.ts
import { test, expect } from '@playwright/test'
import { mockAdminBootstrapRoutes, mockEntityTypesRoute } from './fixtures/routes'
import {
  mockUnauthenticatedSession,
  mockLoginSuccess,
  mockLoginRateLimited,
  mockLogout,
} from './fixtures/auth'

test.describe('Login page', () => {
  test('unauthenticated user is redirected to /login', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await page.goto('/')
    await expect(page).toHaveURL(/\/login/)
  })

  test('login page renders Split Panel with app name', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await page.goto('/login')
    await expect(page.locator('.auth-brand-title')).toContainText('Waaseyaa')
    await expect(page.locator('#login-username')).toBeVisible()
    await expect(page.locator('#login-password')).toBeVisible()
  })

  test('successful login redirects to dashboard', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await mockLoginSuccess(page)

    await page.goto('/login')
    await page.fill('#login-username', 'dev-admin')
    await page.fill('#login-password', 'password')

    // After login succeeds, the page will try to navigate to /
    // which needs authenticated routes
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)

    await page.click('button[type="submit"]')
    await expect(page).toHaveURL(/\/$/)
  })

  test('failed login shows error message', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await mockLoginSuccess(page, 'dev-admin', 'correct-password')

    await page.goto('/login')
    await page.fill('#login-username', 'dev-admin')
    await page.fill('#login-password', 'wrong-password')
    await page.click('button[type="submit"]')

    await expect(page.locator('[role="alert"]')).toContainText('Invalid credentials')
  })

  test('login with returnTo redirects to original page', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await mockLoginSuccess(page)
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)

    await page.goto('/login?returnTo=/user')
    await page.fill('#login-username', 'dev-admin')
    await page.fill('#login-password', 'password')
    await page.click('button[type="submit"]')

    await expect(page).toHaveURL(/\/user/)
  })

  test('keyboard-only login flow', async ({ page }) => {
    await mockUnauthenticatedSession(page)
    await mockLoginSuccess(page)
    await mockAdminBootstrapRoutes(page)
    await mockEntityTypesRoute(page)

    await page.goto('/login')
    await page.keyboard.press('Tab') // Focus username
    await page.keyboard.type('dev-admin')
    await page.keyboard.press('Tab') // Focus password
    await page.keyboard.type('password')
    await page.keyboard.press('Enter') // Submit

    await expect(page).toHaveURL(/\/$/)
  })

  test('mobile viewport stacks panels vertically', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 })
    await mockUnauthenticatedSession(page)
    await page.goto('/login')

    const brand = page.locator('.auth-brand')
    const form = page.locator('.auth-form-panel')

    // Both should be visible and stacked (brand above form)
    await expect(brand).toBeVisible()
    await expect(form).toBeVisible()

    const brandBox = await brand.boundingBox()
    const formBox = await form.boundingBox()
    expect(brandBox!.y).toBeLessThan(formBox!.y)
  })
})
```

- [ ] **Step 2: Run E2E tests**

Run: `cd packages/admin && npx playwright test e2e/login.spec.ts --reporter=list`
Expected: All 6 tests PASS

- [ ] **Step 3: Commit**

```bash
git add packages/admin/e2e/login.spec.ts
git commit -m "test(admin): add Playwright E2E tests for login flow (#766)"
```

---

## Task 11: scaffold:auth CLI command

**Issue:** #765
**Files:**
- Create: `packages/cli/src/Command/ScaffoldAuthCommand.php`
- Create: `packages/cli/tests/Unit/ScaffoldAuthCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// packages/cli/tests/Unit/ScaffoldAuthCommandTest.php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\ScaffoldAuthCommand;

#[CoversClass(ScaffoldAuthCommand::class)]
final class ScaffoldAuthCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_scaffold_test_' . uniqid();
        mkdir($this->tempDir . '/packages/admin/app/pages', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/components/auth', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/composables', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/assets', 0755, true);

        // Create source files
        file_put_contents($this->tempDir . '/packages/admin/app/pages/login.vue', '<template>login</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/components/auth/LoginForm.vue', '<template>form</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/components/auth/BrandPanel.vue', '<template>brand</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/composables/useAuth.ts', 'export function useAuth() {}');
        file_put_contents($this->tempDir . '/packages/admin/app/assets/auth.css', ':root {}');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function it_copies_all_auth_files(): void
    {
        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($this->tempDir . '/app/pages/login.vue');
        self::assertFileExists($this->tempDir . '/app/components/auth/LoginForm.vue');
        self::assertFileExists($this->tempDir . '/app/components/auth/BrandPanel.vue');
        self::assertFileExists($this->tempDir . '/app/composables/useAuth.ts');
        self::assertFileExists($this->tempDir . '/app/assets/auth.css');
    }

    #[Test]
    public function it_skips_existing_files_without_force(): void
    {
        mkdir($this->tempDir . '/app/pages', 0755, true);
        file_put_contents($this->tempDir . '/app/pages/login.vue', 'custom');

        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('custom', file_get_contents($this->tempDir . '/app/pages/login.vue'));
        self::assertStringContainsString('SKIP', $tester->getDisplay());
    }

    #[Test]
    public function it_overwrites_with_force(): void
    {
        mkdir($this->tempDir . '/app/pages', 0755, true);
        file_put_contents($this->tempDir . '/app/pages/login.vue', 'custom');

        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute(['--force' => true]);

        self::assertStringContainsString('<template>login</template>', file_get_contents($this->tempDir . '/app/pages/login.vue'));
    }

    #[Test]
    public function dry_run_does_not_write_files(): void
    {
        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        self::assertFileDoesNotExist($this->tempDir . '/app/pages/login.vue');
        self::assertStringContainsString('login.vue', $tester->getDisplay());
    }

    #[Test]
    public function it_writes_scaffold_manifest(): void
    {
        $command = new ScaffoldAuthCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $manifestPath = $this->tempDir . '/app/.waaseyaa/scaffold-manifest.json';
        self::assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true);
        self::assertArrayHasKey('pages/login.vue', $manifest);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/cli/tests/Unit/ScaffoldAuthCommandTest.php`
Expected: FAIL — class ScaffoldAuthCommand not found

- [ ] **Step 3: Write the ScaffoldAuthCommand**

```php
<?php
// packages/cli/src/Command/ScaffoldAuthCommand.php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scaffold:auth',
    description: 'Copy framework auth UI files into your app for customization',
)]
final class ScaffoldAuthCommand extends Command
{
    /** @var array<string, string> source (relative to packages/admin/app/) => dest (relative to app/) */
    private const FILE_MAP = [
        'pages/login.vue' => 'pages/login.vue',
        'components/auth/LoginForm.vue' => 'components/auth/LoginForm.vue',
        'components/auth/BrandPanel.vue' => 'components/auth/BrandPanel.vue',
        'composables/useAuth.ts' => 'composables/useAuth.ts',
        'assets/auth.css' => 'assets/auth.css',
    ];

    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be copied without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $sourceBase = $this->projectRoot . '/packages/admin/app/';
        $destBase = $this->projectRoot . '/app/';
        $checksums = [];
        $copied = 0;
        $skipped = 0;

        foreach (self::FILE_MAP as $source => $dest) {
            $sourcePath = $sourceBase . $source;
            $destPath = $destBase . $dest;

            if (!file_exists($sourcePath)) {
                $output->writeln(sprintf('  <error>MISSING</error> %s (source not found)', $source));
                continue;
            }

            if ($dryRun) {
                $output->writeln(sprintf('  <info>COPY</info> %s → app/%s', $source, $dest));
                $copied++;
                continue;
            }

            if (file_exists($destPath) && !$force) {
                $output->writeln(sprintf('  <comment>SKIP</comment> app/%s (already exists, use --force to overwrite)', $dest));
                $skipped++;
                continue;
            }

            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            copy($sourcePath, $destPath);
            $checksums[$dest] = md5_file($sourcePath);
            $output->writeln(sprintf('  <info>COPY</info> %s → app/%s', $source, $dest));
            $copied++;
        }

        // Write scaffold manifest (unless dry-run)
        if (!$dryRun && $checksums !== []) {
            $manifestDir = $destBase . '.waaseyaa';
            if (!is_dir($manifestDir)) {
                mkdir($manifestDir, 0755, true);
            }
            $manifestPath = $manifestDir . '/scaffold-manifest.json';

            // Merge with existing manifest
            $existing = file_exists($manifestPath)
                ? (json_decode(file_get_contents($manifestPath), true) ?? [])
                : [];
            $merged = array_merge($existing, $checksums);
            file_put_contents($manifestPath, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }

        $output->writeln('');
        if ($dryRun) {
            $output->writeln(sprintf('<info>Dry run:</info> %d file(s) would be copied.', $copied));
        } else {
            $output->writeln(sprintf('<info>Done:</info> %d copied, %d skipped.', $copied, $skipped));
            if ($copied > 0) {
                $output->writeln('');
                $output->writeln('You now own these files. Framework updates will no longer flow to them.');
                $output->writeln('Run <comment>bin/waaseyaa scaffold:auth --dry-run</comment> to compare with upstream.');
            }
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/cli/tests/Unit/ScaffoldAuthCommandTest.php`
Expected: 5 tests PASS

- [ ] **Step 5: Commit**

```bash
git add packages/cli/src/Command/ScaffoldAuthCommand.php packages/cli/tests/Unit/ScaffoldAuthCommandTest.php
git commit -m "feat(cli): add scaffold:auth command for auth UI ejection (#765)"
```

---

## Task 12: Run full test suite and verify

**Files:** None (verification only)

- [ ] **Step 1: Run PHP tests**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All tests PASS, no regressions

- [ ] **Step 2: Run Vitest**

Run: `cd packages/admin && npx vitest run`
Expected: All tests PASS including new useAuth, LoginForm, BrandPanel tests

- [ ] **Step 3: Run Playwright**

Run: `cd packages/admin && npx playwright test`
Expected: All E2E tests PASS including new login.spec.ts

- [ ] **Step 4: Run build**

Run: `cd packages/admin && npm run build`
Expected: Build succeeds with no errors

- [ ] **Step 5: Run code style**

Run: `composer cs-check`
Expected: No violations in new PHP files

---

## Task Dependency Graph

```
Task 1 (auth.css)
  ↓
Task 2 (BrandPanel) ──┐
  ↓                    │
Task 3 (LoginForm) ────┤
  ↓                    │
Task 4 (useAuth) ──────┤
  ↓                    │
Task 5 (login.vue) ←───┘  (depends on Tasks 1-4)
  ↓
Task 6 (admin.ts fix)     (independent, can run after Task 4)
  ↓
Task 7 (rate limiting)    (independent PHP, can run anytime)
  ↓
Task 8 (cookie security)  (independent PHP, can run anytime)
  ↓
Task 9 (E2E fixtures)     (depends on Tasks 5-6)
  ↓
Task 10 (E2E tests)       (depends on Task 9)
  ↓
Task 11 (scaffold CLI)    (depends on Tasks 1-5 for source files)
  ↓
Task 12 (full verification)
```

**Parallelizable groups:**
- Tasks 2 + 3 can run in parallel (independent components)
- Tasks 7 + 8 can run in parallel with Tasks 2-6 (PHP-only, no frontend deps)
- Task 11 can run in parallel with Tasks 9-10 (PHP CLI, independent of E2E)

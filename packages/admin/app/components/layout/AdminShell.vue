<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useAdmin } from '~/composables/useAdmin'

const { t, locale, locales, setLocale } = useLanguage()
const config = useRuntimeConfig()
const { tenant } = useAdmin()
const appName = tenant?.name ?? (config.public.appName as string)
const sidebarOpen = ref(false)

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

// Close sidebar on route change (mobile).
const route = useRoute()
watch(() => route.fullPath, () => {
  sidebarOpen.value = false
})

function onLocaleChange(event: Event) {
  setLocale((event.target as HTMLSelectElement).value)
}
</script>

<template>
  <div class="admin-shell">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <header class="topbar" role="banner">
      <button class="topbar-toggle" :aria-label="t('toggle_menu')" @click="toggleSidebar">
        <span class="topbar-toggle-icon">&#9776;</span>
      </button>
      <NuxtLink to="/" class="topbar-brand">{{ appName }}</NuxtLink>
      <ClientOnly>
        <label class="topbar-locale">
          <span class="sr-only">{{ t('language') }}</span>
          <select
            class="topbar-locale-select"
            :value="locale"
            :aria-label="t('language')"
            @change="onLocaleChange"
          >
            <option v-for="code in locales" :key="code" :value="code">
              {{ code.toUpperCase() }}
            </option>
          </select>
        </label>
      </ClientOnly>
    </header>

    <div class="admin-body">
      <div v-if="sidebarOpen" class="sidebar-overlay" @click="sidebarOpen = false" />
      <aside
        class="sidebar"
        :class="{ 'sidebar--open': sidebarOpen }"
        role="navigation"
        :aria-label="t('sidebar_nav')"
      >
        <ClientOnly>
          <LayoutNavBuilder />
        </ClientOnly>
      </aside>
      <main id="main-content" class="content" role="main">
        <slot />
      </main>
    </div>
  </div>
</template>

<style>
:root {
  --sidebar-width: 220px;
  --topbar-height: 48px;
  --color-bg: #f5f5f5;
  --color-surface: #fff;
  --color-primary: #2563eb;
  --color-primary-hover: #1d4ed8;
  --color-danger: #dc2626;
  --color-text: #1f2937;
  --color-muted: #6b7280;
  --color-border: #e5e7eb;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  color: var(--color-text);
  background: var(--color-bg);
}

.admin-shell {
  min-height: 100vh;
}

.topbar {
  height: var(--topbar-height);
  background: var(--color-primary);
  color: #fff;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
}

.topbar-brand {
  color: #fff;
  text-decoration: none;
  font-weight: 600;
  font-size: 16px;
  margin-right: auto;
}

.topbar-toggle {
  display: none;
  background: none;
  border: none;
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  padding: 0 8px;
  margin-right: 8px;
}

.topbar-locale {
  display: inline-flex;
  align-items: center;
}

.topbar-locale-select {
  height: 32px;
  border: 1px solid rgba(255, 255, 255, 0.35);
  background: rgba(255, 255, 255, 0.15);
  color: #fff;
  border-radius: 4px;
  padding: 0 8px;
  font-size: 12px;
  font-weight: 600;
}

.admin-body {
  display: flex;
  min-height: calc(100vh - var(--topbar-height));
}

.sidebar {
  width: var(--sidebar-width);
  background: var(--color-surface);
  border-right: 1px solid var(--color-border);
  padding: 16px 0;
}

.sidebar-overlay {
  display: none;
}

.content {
  flex: 1;
  padding: 24px;
}

/* Shared utility styles */
.btn {
  display: inline-block;
  padding: 8px 16px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-surface);
  color: var(--color-text);
  cursor: pointer;
  font-size: 14px;
  text-decoration: none;
  transition: background 0.15s, border-color 0.15s;
}
.btn:hover { background: var(--color-bg); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
.btn-primary:hover { background: var(--color-primary-hover); }
.btn-danger { color: var(--color-danger); border-color: var(--color-danger); }
.btn-sm { padding: 4px 10px; font-size: 12px; }

.field { margin-bottom: 16px; }
.field-label { display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px; }
.field-input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  font-size: 14px;
  font-family: inherit;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.field-input:focus { outline: none; border-color: var(--color-primary); box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15); }
.field-textarea { resize: vertical; min-height: 100px; }
.field-richtext { min-height: 120px; padding: 8px 12px; border: 1px solid var(--color-border); border-radius: 4px; }
.field-description { margin-top: 4px; font-size: 12px; color: var(--color-muted); }
.required { color: var(--color-danger); }

.entity-table { width: 100%; border-collapse: collapse; background: var(--color-surface); border-radius: 4px; }
.entity-table th, .entity-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.entity-table th { font-weight: 600; font-size: 13px; color: var(--color-muted); text-transform: uppercase; letter-spacing: 0.03em; }
.entity-table th.sortable { cursor: pointer; }
.entity-table .empty { text-align: center; color: var(--color-muted); padding: 40px; }
.entity-table .actions { white-space: nowrap; }
.entity-table .actions > * { margin-right: 4px; }

.pagination { display: flex; align-items: center; gap: 12px; margin-top: 16px; font-size: 14px; color: var(--color-muted); }

.form-actions { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--color-border); }

.loading { padding: 40px; text-align: center; color: var(--color-muted); }
.error { padding: 12px 16px; background: #fef2f2; color: var(--color-danger); border-radius: 4px; margin-bottom: 16px; }
.success { padding: 12px 16px; background: #f0fdf4; color: #16a34a; border-radius: 4px; margin-bottom: 16px; }

.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.page-header h1 { font-size: 24px; font-weight: 600; }

/* Skip link for accessibility */
.skip-link {
  position: absolute;
  top: -100%;
  left: 16px;
  background: var(--color-primary);
  color: #fff;
  padding: 8px 16px;
  border-radius: 0 0 4px 4px;
  z-index: 1000;
  font-size: 14px;
  text-decoration: none;
}
.skip-link:focus {
  top: 0;
}

/* Screen reader only utility */
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* SSE connection indicator */
.sse-status {
  display: inline-block;
  color: #16a34a;
  font-size: 10px;
  margin-left: 8px;
  vertical-align: middle;
  animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

/* Responsive layout */
@media (max-width: 768px) {
  .topbar-toggle {
    display: inline-flex;
    align-items: center;
  }

  .sidebar {
    position: fixed;
    top: var(--topbar-height);
    left: 0;
    bottom: 0;
    z-index: 50;
    transform: translateX(-100%);
    transition: transform 0.2s ease;
  }

  .sidebar--open {
    transform: translateX(0);
  }

  .sidebar-overlay {
    display: block;
    position: fixed;
    inset: 0;
    top: var(--topbar-height);
    background: rgba(0, 0, 0, 0.3);
    z-index: 40;
  }

  .content {
    padding: 16px;
  }

  .page-header h1 {
    font-size: 20px;
  }

  .entity-table {
    font-size: 13px;
  }
  .entity-table th, .entity-table td {
    padding: 8px;
  }
}
</style>

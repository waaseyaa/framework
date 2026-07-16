<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useAdmin } from '~/composables/useAdmin'
import { adminNavLinkIsExternal } from '~/runtime/navLinkExternal'

const { t, locale, locales, setLocale } = useLanguage()
const { appName: configAppName } = useAdminConfig()
const { tenant, ui } = useAdmin()
const appName = tenant?.name ?? configAppName
const sidebarOpen = ref(false)
const isMobile = ref(false)
const sidebarRef = ref<HTMLElement | null>(null)
const sidebarToggleRef = ref<HTMLButtonElement | null>(null)
const sidebarCloseRef = ref<HTMLButtonElement | null>(null)
let mobileQuery: MediaQueryList | null = null
let previousBodyOverflow = ''

function unlockPageScroll() {
  document.body.style.overflow = previousBodyOverflow
}

function closeSidebar(restoreFocus = false) {
  const wasOpen = sidebarOpen.value
  sidebarOpen.value = false
  if (import.meta.client) unlockPageScroll()
  if (restoreFocus && wasOpen) void nextTick(() => sidebarToggleRef.value?.focus())
}

async function openSidebar() {
  if (!isMobile.value) return
  previousBodyOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  sidebarOpen.value = true
  await nextTick()
  sidebarCloseRef.value?.focus()
}

function toggleSidebar() {
  if (sidebarOpen.value) closeSidebar(true)
  else void openSidebar()
}

function onMobileChange(event: MediaQueryListEvent | MediaQueryList) {
  isMobile.value = event.matches
  if (!event.matches) closeSidebar(false)
}

function focusableSidebarElements(): HTMLElement[] {
  if (!sidebarRef.value) return []
  return Array.from(sidebarRef.value.querySelectorAll<HTMLElement>(
    'a[href], button:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )).filter(element => !element.hasAttribute('inert'))
}

function onSidebarKeydown(event: KeyboardEvent) {
  if (!sidebarOpen.value || !isMobile.value) return
  if (event.key === 'Escape') {
    event.preventDefault()
    closeSidebar(true)
    return
  }
  if (event.key !== 'Tab') return

  const focusable = focusableSidebarElements()
  const first = focusable[0]
  const last = focusable.at(-1)
  if (!first || !last) return
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  }
  else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

// Close sidebar on route change (mobile).
const route = useRoute()
watch(() => route.fullPath, () => {
  closeSidebar(false)
})

onMounted(() => {
  mobileQuery = window.matchMedia('(max-width: 768px)')
  onMobileChange(mobileQuery)
  mobileQuery.addEventListener('change', onMobileChange)
})

onBeforeUnmount(() => {
  mobileQuery?.removeEventListener('change', onMobileChange)
  unlockPageScroll()
})

function onLocaleChange(event: Event) {
  setLocale((event.target as HTMLSelectElement).value)
}
</script>

<template>
  <div class="admin-shell">
    <a href="#main-content" class="skip-link">{{ t('skip_to_main_content') }}</a>
    <header class="topbar" role="banner">
      <button
        ref="sidebarToggleRef"
        class="topbar-toggle touch-target"
        :aria-label="t('toggle_menu')"
        aria-controls="admin-sidebar"
        :aria-expanded="sidebarOpen ? 'true' : 'false'"
        @click="toggleSidebar"
      >
        <Icon name="heroicons:bars-3" class="topbar-toggle-icon" aria-hidden="true" />
      </button>
      <NuxtLink to="/" class="topbar-brand touch-target">{{ appName }}</NuxtLink>
      <nav
        v-if="ui.navigationMode !== 'catalog-only' && ui.headerLinks.length > 0"
        class="topbar-links"
        :aria-label="t('topbar_links')"
      >
        <template v-for="(link, idx) in ui.headerLinks" :key="idx">
          <a
            v-if="adminNavLinkIsExternal(link)"
            :href="link.href"
            class="topbar-link touch-target"
            target="_blank"
            rel="noopener noreferrer"
          >{{ link.label }}</a>
          <NuxtLink
            v-else
            :to="link.href"
            class="topbar-link touch-target"
          >{{ link.label }}</NuxtLink>
        </template>
      </nav>
      <div class="topbar-spacer" aria-hidden="true" />
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
      <button
        v-if="sidebarOpen && isMobile"
        type="button"
        class="sidebar-overlay"
        :aria-label="t('close_menu')"
        tabindex="-1"
        @click="closeSidebar(true)"
      />
      <aside
        id="admin-sidebar"
        ref="sidebarRef"
        class="sidebar"
        :class="{ 'sidebar--open': sidebarOpen }"
        role="navigation"
        :aria-label="t('sidebar_nav')"
        :aria-hidden="isMobile && !sidebarOpen ? 'true' : undefined"
        :inert="isMobile && !sidebarOpen ? true : undefined"
        :data-pointer-state="isMobile && !sidebarOpen ? 'disabled' : 'enabled'"
        @keydown="onSidebarKeydown"
      >
        <button
          ref="sidebarCloseRef"
          type="button"
          class="sidebar-close btn touch-target"
          :aria-label="t('close_menu')"
          @click="closeSidebar(true)"
        >
          <span aria-hidden="true">×</span>
          <span>{{ t('close') }}</span>
        </button>
        <ClientOnly>
          <LayoutNavBuilder />
        </ClientOnly>
      </aside>
      <main id="main-content" class="content" role="main" :inert="isMobile && sidebarOpen ? true : undefined">
        <slot />
      </main>
    </div>
  </div>
</template>

<style>
:root {
  --admin-target-size: 44px;
  --sidebar-width: 220px;
  --topbar-height: 48px;
  --color-bg: #f5f5f5;
  --color-surface: #fff;
  --color-primary: #0f766e;
  --color-primary-hover: #0d4f4f;
  --color-danger: #b42318;
  --color-text: #1f2937;
  --color-muted: #6b7280;
  --color-border: #e5e7eb;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  color: var(--color-text);
  background: var(--color-bg);
  overflow-wrap: anywhere;
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
  display: inline-flex;
  align-items: center;
  color: #fff;
  text-decoration: none;
  font-weight: 600;
  font-size: 16px;
  margin-right: 12px;
}

.topbar-spacer {
  flex: 1 1 auto;
  min-width: 8px;
}

.topbar-links {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
}

.topbar-link {
  display: inline-flex;
  align-items: center;
  color: rgba(255, 255, 255, 0.95);
  text-decoration: none;
  font-size: 13px;
  font-weight: 500;
  white-space: nowrap;
}

.topbar-link:hover {
  text-decoration: underline;
}

.topbar-toggle {
  display: none;
  background: none;
  border: none;
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  padding: 0;
  margin-right: 8px;
}

.topbar-locale {
  display: inline-flex;
  align-items: center;
}

.topbar-locale-select {
  min-width: 44px;
  height: 44px;
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
  width: 100%;
  max-width: 100%;
  min-width: 0;
  min-height: calc(100vh - var(--topbar-height));
}

.sidebar {
  flex: 0 0 var(--sidebar-width);
  width: var(--sidebar-width);
  background: var(--color-surface);
  border-right: 1px solid var(--color-border);
  padding: 16px 0;
}

.sidebar[data-pointer-state="disabled"] {
  pointer-events: none;
}

.sidebar-close {
  display: none;
  margin: 0 12px 12px;
  gap: 8px;
}

.sidebar-overlay {
  display: none;
}

.content {
  flex: 1;
  min-width: 0;
  max-width: 100%;
  padding: 24px;
  overflow-x: clip;
}

/* Shared utility styles */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: var(--admin-target-size);
  min-height: var(--admin-target-size);
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
.touch-target {
  min-inline-size: var(--admin-target-size);
  min-block-size: var(--admin-target-size);
}

/* Ordinary authenticated-admin actions and form controls share one effective
   target floor. Native checkbox/radio glyphs stay compact inside a labelled
   target supplied by their widget. */
.admin-shell :where(
  a[href]:not(.skip-link),
  button,
  input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
  select
) {
  min-inline-size: var(--admin-target-size);
  min-block-size: var(--admin-target-size);
}

/* Inline anchors ignore logical minimum sizes. Promote authenticated-admin
   links to inline flex targets without forcing them to fill their container. */
.admin-shell a[href]:not(.skip-link) {
  display: inline-flex;
  align-items: center;
}
.btn:hover { background: var(--color-bg); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn:focus-visible,
.topbar-toggle:focus-visible,
.topbar-locale-select:focus-visible,
a:focus-visible,
button:focus-visible,
select:focus-visible {
  outline: 3px solid var(--color-primary-hover);
  outline-offset: 2px;
}
.topbar-toggle:focus-visible,
.topbar-locale-select:focus-visible,
.topbar-link:focus-visible,
.topbar-brand:focus-visible {
  outline-color: #fff;
}
.btn-primary { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
.btn-primary:hover { background: var(--color-primary-hover); }
.btn-danger { color: var(--color-danger); border-color: var(--color-danger); }
.btn-sm { padding: 4px 10px; font-size: 12px; }

.field { margin-bottom: 16px; }
.field-label { display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px; }
.field-input {
  min-block-size: var(--admin-target-size);
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  font-size: 14px;
  font-family: inherit;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.field-input:focus-visible {
  border-color: var(--color-primary-hover);
  outline: 3px solid var(--color-primary-hover);
  outline-offset: 2px;
  box-shadow: none;
}
.field-textarea { resize: vertical; min-height: 100px; }
.field-richtext { min-height: 120px; padding: 8px 12px; border: 1px solid var(--color-border); border-radius: 4px; }
.field-description { margin-top: 4px; font-size: 12px; color: #4b5563; }
.field-error { margin-top: 4px; color: var(--color-danger); font-weight: 600; }
.required { color: var(--color-danger); }

.entity-table { width: 100%; border-collapse: collapse; background: var(--color-surface); border-radius: 4px; }
.entity-table th, .entity-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.entity-table th { font-weight: 600; font-size: 13px; color: var(--color-muted); text-transform: uppercase; letter-spacing: 0.03em; }
.entity-table th.sortable { cursor: pointer; }
.entity-table .empty { text-align: center; color: var(--color-muted); padding: 40px; }
.entity-table .actions { white-space: nowrap; }
.entity-table .actions > * { margin-right: 4px; }

.pagination { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 16px; font-size: 14px; color: var(--color-muted); }
.pagination-summary { flex: 1 1 100%; }
.pagination-ellipsis { display: inline-flex; align-items: center; justify-content: center; min-width: 1.5rem; }

.form-actions { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--color-border); }

.loading { padding: 40px; text-align: center; color: var(--color-muted); }
.error { padding: 12px 16px; background: #fef2f2; color: var(--color-danger); border-radius: 4px; margin-bottom: 16px; }
.success { padding: 12px 16px; background: #f0fdf4; color: #16a34a; border-radius: 4px; margin-bottom: 16px; }

.page-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 24px; }
.page-header h1 { font-size: 24px; font-weight: 600; }
.page-actions,
.page-header-actions,
.form-actions,
.modal-actions {
  min-width: 0;
  max-width: 100%;
  flex-wrap: wrap;
}

pre,
code,
.breadcrumbs,
.breadcrumb,
.alert,
.error,
.success {
  max-width: 100%;
  overflow-wrap: anywhere;
}

pre {
  overflow-x: auto;
  white-space: pre-wrap;
}

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
    max-width: min(var(--sidebar-width), calc(100vw - 48px));
    overflow-y: auto;
    overscroll-behavior: contain;
  }

  .sidebar--open {
    transform: translateX(0);
  }

  .sidebar-close {
    display: inline-flex;
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
    width: 100%;
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

@media (prefers-reduced-motion: reduce) {
  .sidebar {
    transition: none;
  }
}
</style>

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
        <svg
          class="topbar-toggle-icon"
          aria-hidden="true"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
        >
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
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

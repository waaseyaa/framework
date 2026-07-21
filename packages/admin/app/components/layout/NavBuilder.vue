<script setup lang="ts">
import type { AdminSurfaceSidebarItem } from '~/contracts/surface-ui'
import { useLanguage } from '~/composables/useLanguage'
import { useAdmin } from '~/composables/useAdmin'
import { groupEntityTypes } from '~/composables/useNavGroups'
import { adminNavLinkIsExternal } from '~/runtime/navLinkExternal'

const { t, entityLabel } = useLanguage()
const { catalog, features, ui } = useAdmin()

const navGroups = computed(() => groupEntityTypes(catalog))
const showOperationalNavigation = computed(() => ui.navigationMode !== 'catalog-only')
const showMcpNavigation = computed(() => showOperationalNavigation.value && features?.mcp === true)

/** Group key → items (sorted by weight). Empty key = default “custom” bucket. */
const customNavSections = computed((): Array<[string, AdminSurfaceSidebarItem[]]> => {
  if (!showOperationalNavigation.value) return []

  const map = new Map<string, AdminSurfaceSidebarItem[]>()
  for (const item of ui.sidebarItems) {
    const key = item.group ?? ''
    if (!map.has(key)) {
      map.set(key, [])
    }
    map.get(key)!.push(item)
  }
  for (const list of map.values()) {
    list.sort((a, b) => (a.weight ?? 0) - (b.weight ?? 0))
  }
  const entries = [...map.entries()]
  entries.sort((a, b) => {
    if (a[0] === '') {
      return -1
    }
    if (b[0] === '') {
      return 1
    }

    return a[0].localeCompare(b[0])
  })

  return entries
})

function customSectionHeading(groupKey: string): string {
  if (groupKey === '') {
    return t('nav_group_custom')
  }
  if (groupKey.startsWith('nav_')) {
    return t(groupKey)
  }

  return groupKey
}
</script>

<template>
  <nav class="nav">
    <NuxtLink v-if="showOperationalNavigation" to="/" class="nav-item touch-target">
      {{ t('dashboard') }}
    </NuxtLink>
    <template v-for="[groupKey, items] in customNavSections" :key="'cu-' + groupKey">
      <div class="nav-section">{{ customSectionHeading(groupKey) }}</div>
      <template v-for="it in items" :key="it.id">
        <a
          v-if="adminNavLinkIsExternal(it)"
          :href="it.href"
          class="nav-item touch-target"
          target="_blank"
          rel="noopener noreferrer"
        >{{ it.label }}</a>
        <NuxtLink
          v-else
          :to="it.href"
          class="nav-item touch-target"
        >{{ it.label }}</NuxtLink>
      </template>
    </template>
    <template v-for="group in navGroups" :key="group.key">
      <div class="nav-section">{{ t(group.labelKey) }}</div>
      <template v-for="et in group.entityTypes" :key="et.id">
        <NuxtLink
          :to="`/${et.id}`"
          class="nav-item touch-target"
        >
          {{ entityLabel(et.id, et.label) }}
        </NuxtLink>
        <NuxtLink
          v-if="et.actions.some(action => action.id === 'board-config')"
          :to="`/${et.id}/pipeline`"
          class="nav-item nav-item--sub touch-target"
        >
          {{ entityLabel(et.id, et.label) }} {{ t('entity_type_pipeline') }}
        </NuxtLink>
      </template>
    </template>
    <template v-if="showMcpNavigation">
      <div class="nav-section" data-testid="nav-section-mcp">{{ t('nav_group_mcp') }}</div>
      <NuxtLink to="/mcp/tools" class="nav-item touch-target" data-testid="nav-mcp-tools">{{ t('mcp_tools_title') }}</NuxtLink>
      <NuxtLink to="/mcp/server-config" class="nav-item touch-target" data-testid="nav-mcp-server-config">{{ t('mcp_server_config_title') }}</NuxtLink>
    </template>
    <template v-if="showOperationalNavigation">
      <div class="nav-section" data-testid="nav-section-operations">{{ t('nav_group_operations') }}</div>
      <NuxtLink to="/workflows" class="nav-item touch-target">{{ t('workflows') }}</NuxtLink>
      <NuxtLink to="/queue" class="nav-item touch-target" data-testid="nav-queue">{{ t('queue_title') }}</NuxtLink>
      <NuxtLink to="/scheduler" class="nav-item touch-target" data-testid="nav-scheduler">{{ t('scheduler_title') }}</NuxtLink>
      <NuxtLink to="/notifications" class="nav-item touch-target" data-testid="nav-notifications">{{ t('notifications_title') }}</NuxtLink>
      <NuxtLink to="/mercure/monitor" class="nav-item touch-target" data-testid="nav-mercure-monitor">{{ t('mercure_monitor_title') }}</NuxtLink>
      <div class="nav-section" data-testid="nav-section-governance">{{ t('nav_group_governance') }}</div>
      <NuxtLink to="/classification/policies" class="nav-item touch-target" data-testid="nav-classification-policies">{{ t('classification_policies_nav') }}</NuxtLink>
    </template>
  </nav>
</template>

<style scoped>
.nav { display: flex; flex-direction: column; }
.nav-section {
  padding: 12px 16px 4px;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.nav-item {
  display: flex;
  align-items: center;
  padding: 8px 16px;
  color: var(--color-text);
  text-decoration: none;
  font-size: 14px;
  transition: background 0.15s;
}
.nav-item:hover { background: var(--color-bg); }
.nav-item.router-link-active { color: var(--color-primary); font-weight: 500; }
.nav-item--sub { padding-left: 32px; font-size: 13px; color: var(--color-muted); }
.nav-error {
  padding: 8px 16px;
  font-size: 12px;
  color: var(--color-danger, #c00);
}
</style>

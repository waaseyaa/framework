<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useAdmin } from '~/composables/useAdmin'
import { groupEntityTypes } from '~/composables/useNavGroups'

const { t, entityLabel } = useLanguage()
const { catalog } = useAdmin()

const navGroups = computed(() => groupEntityTypes(catalog))
</script>

<template>
  <nav class="nav">
    <NuxtLink to="/" class="nav-item">
      {{ t('dashboard') }}
    </NuxtLink>
    <template v-for="group in navGroups" :key="group.key">
      <div class="nav-section">{{ t(group.labelKey, group.label) }}</div>
      <NuxtLink
        v-for="et in group.entityTypes"
        :key="et.id"
        :to="`/${et.id}`"
        class="nav-item"
      >
        {{ entityLabel(et.id, et.label) }}
      </NuxtLink>
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
  padding: 8px 16px;
  color: var(--color-text);
  text-decoration: none;
  font-size: 14px;
  transition: background 0.15s;
}
.nav-item:hover { background: var(--color-bg); }
.nav-item.router-link-active { color: var(--color-primary); font-weight: 500; }
.nav-error {
  padding: 8px 16px;
  font-size: 12px;
  color: var(--color-danger, #c00);
}
</style>

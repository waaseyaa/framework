<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { groupEntityTypes, type EntityTypeInfo } from '~/composables/useNavGroups'

const { t } = useLanguage()

const entityTypes = ref<EntityTypeInfo[]>([])
const loadError = ref(false)

onMounted(async () => {
  try {
    const response = await $fetch<{ data: EntityTypeInfo[] }>('/api/entity-types')
    entityTypes.value = response.data
  } catch (e: unknown) {
    console.error('[Waaseyaa] Failed to load navigation entity types:', e)
    loadError.value = true
  }
})

const navGroups = computed(() => groupEntityTypes(entityTypes.value))
</script>

<template>
  <nav class="nav">
    <NuxtLink to="/" class="nav-item">
      {{ t('dashboard') }}
    </NuxtLink>
    <div v-if="loadError" class="nav-error">{{ t('error_nav') }}</div>
    <template v-for="group in navGroups" :key="group.key">
      <div class="nav-section">{{ t(group.labelKey) }}</div>
      <NuxtLink
        v-for="et in group.entityTypes"
        :key="et.id"
        :to="`/${et.id}`"
        class="nav-item"
      >
        {{ et.label }}
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

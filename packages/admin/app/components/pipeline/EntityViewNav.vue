<script setup lang="ts">
import { useAdmin } from '~/composables/useAdmin'

const props = defineProps<{
  entityType: string
}>()

const { getEntity } = useAdmin()
const route = useRoute()

const activeTab = computed(() => {
  const path = route.path
  if (path.endsWith('/pipeline')) return 'pipeline'
  return 'list'
})

const hasPipeline = computed(() => {
  const entry = getEntity(props.entityType)
  return !!entry && entry.actions.some(action => action.id === 'board-config')
})
</script>

<template>
  <nav v-if="hasPipeline" class="entity-view-nav">
    <NuxtLink
      :to="`/${entityType}`"
      class="nav-tab"
      :class="{ active: activeTab === 'list' }"
    >
      List
    </NuxtLink>
    <NuxtLink
      :to="`/${entityType}/pipeline`"
      class="nav-tab"
      :class="{ active: activeTab === 'pipeline' }"
    >
      Pipeline
    </NuxtLink>
  </nav>
</template>

<style scoped>
.entity-view-nav {
  display: flex;
  gap: 2px;
  margin-bottom: 16px;
  border-bottom: 2px solid var(--color-border);
}

.nav-tab {
  padding: 8px 16px;
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-muted, #64748b);
  text-decoration: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  transition: color 0.15s ease, border-color 0.15s ease;
}

.nav-tab:hover {
  color: var(--color-text);
}

.nav-tab.active {
  color: var(--color-primary, #3b82f6);
  border-bottom-color: var(--color-primary, #3b82f6);
}
</style>

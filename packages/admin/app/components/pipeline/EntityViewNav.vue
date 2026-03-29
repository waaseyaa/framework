<script setup lang="ts">
import { useEntity } from '~/composables/useEntity'

const props = defineProps<{
  entityType: string
}>()

const { runAction } = useEntity()
const route = useRoute()
const hasPipeline = ref(false)
const checked = ref(false)

const activeTab = computed(() => {
  const path = route.path
  if (path.endsWith('/pipeline')) return 'pipeline'
  return 'list'
})

onMounted(async () => {
  try {
    await runAction(props.entityType, 'board-config')
    hasPipeline.value = true
  } catch {
    hasPipeline.value = false
  } finally {
    checked.value = true
  }
})
</script>

<template>
  <nav v-if="checked && hasPipeline" class="entity-view-nav">
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

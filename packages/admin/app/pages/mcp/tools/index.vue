<script setup lang="ts">
const { rows, loading, error, fetchTools } = useMcpTools()
const { t } = useLanguage()

onMounted(fetchTools)
</script>

<template>
  <div class="p-6">
    <h1 class="text-2xl font-semibold mb-6">{{ t('mcp_tools_title') }}</h1>

    <div v-if="error" class="alert-error mb-4">{{ error }}</div>
    <div v-if="loading" class="text-gray-500">{{ t('common.loading') }}</div>

    <McpToolRegistryTable v-else-if="rows.length > 0" :rows="rows" />

    <p v-else class="text-gray-500">{{ t('mcp_tools_empty') }}</p>
  </div>
</template>

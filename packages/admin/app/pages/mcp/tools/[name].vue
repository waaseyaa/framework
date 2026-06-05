<script setup lang="ts">
const route = useRoute()
const { tool, loading, error, fetchTool } = useMcpTool()
const { t } = useLanguage()

const toolName = computed(() => String(route.params.name))

onMounted(() => fetchTool(toolName.value))
</script>

<template>
  <div class="p-6">
    <div v-if="error" class="alert-error mb-4">{{ error }}</div>
    <div v-if="loading" class="text-gray-500">{{ t('common.loading') }}</div>

    <template v-else-if="tool">
      <!-- Header card -->
      <div class="mb-6">
        <div class="flex items-start justify-between">
          <div>
            <h1 class="text-2xl font-semibold font-mono">{{ tool.name }}</h1>
            <p v-if="tool.category" class="text-sm text-gray-500 mt-0.5">{{ tool.category }}</p>
          </div>
          <NuxtLink to="/mcp/server-config" class="link text-sm">
            {{ t('mcp_server_config_title') }} →
          </NuxtLink>
        </div>

        <!-- Capability chips -->
        <div v-if="tool.requiredCapabilities.length > 0" class="mt-3 flex flex-wrap gap-1">
          <span
            v-for="cap in tool.requiredCapabilities"
            :key="cap"
            class="px-2 py-0.5 text-xs rounded bg-teal-100 text-teal-800 font-medium"
          >
            {{ cap }}
          </span>
        </div>

        <p v-if="tool.summary" class="mt-3 text-gray-700">{{ tool.summary }}</p>
        <p v-if="tool.description && tool.description !== tool.summary" class="mt-2 text-sm text-gray-600">
          {{ tool.description }}
        </p>
      </div>

      <!-- Input schema -->
      <section class="mb-6">
        <McpInputSchemaViewer :schema="tool.inputSchema" />
      </section>

      <!-- Recent invocations -->
      <section>
        <h2 class="text-lg font-medium mb-3">{{ t('mcp_tool_detail_recent_invocations') }}</h2>
        <McpRecentInvocationsTable
          v-if="tool.recentInvocations.length > 0"
          :rows="tool.recentInvocations"
        />
        <p v-else class="text-gray-500 text-sm">{{ t('mcp_tools_empty') }}</p>
      </section>
    </template>
  </div>
</template>

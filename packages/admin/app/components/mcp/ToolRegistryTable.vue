<script setup lang="ts">
import type { McpToolRow } from '~/composables/useMcpTools'

const props = defineProps<{
  rows: McpToolRow[]
}>()

const { t } = useLanguage()
</script>

<template>
  <table class="w-full border-collapse">
    <thead>
      <tr class="border-b">
        <th class="text-left py-2 pr-4">{{ t('mcp_tools_col_name') }}</th>
        <th class="text-left py-2 pr-4">{{ t('mcp_tools_col_category') }}</th>
        <th class="text-left py-2 pr-4">{{ t('mcp_tools_col_capabilities') }}</th>
        <th class="text-left py-2">{{ t('mcp_tools_col_summary') }}</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="row in props.rows" :key="row.name" class="border-b hover:bg-gray-50">
        <td class="py-2 pr-4 font-mono text-sm">
          <NuxtLink :to="`/mcp/tools/${encodeURIComponent(row.name)}`" class="link">
            {{ row.name }}
          </NuxtLink>
        </td>
        <td class="py-2 pr-4 text-sm text-gray-600">{{ row.category ?? '—' }}</td>
        <td class="py-2 pr-4">
          <span
            v-for="cap in row.requiredCapabilities"
            :key="cap"
            class="inline-block mr-1 mb-1 px-2 py-0.5 text-xs rounded bg-teal-100 text-teal-800 font-medium"
          >
            {{ cap }}
          </span>
        </td>
        <td class="py-2 text-sm text-gray-700">{{ row.summary ?? '' }}</td>
      </tr>
    </tbody>
  </table>
</template>

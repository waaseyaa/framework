<script setup lang="ts">
import type { McpRecentInvocation } from '~/composables/useMcpTool'

const props = defineProps<{
  rows: McpRecentInvocation[]
}>()

const { t } = useLanguage()

// M5B detail page route — will be live when that mission ships.
// Until then we check existence by attempting to resolve the route.
const router = useRouter()

function hasM5bRoute(): boolean {
  const resolved = router.resolve('/ai/observability/runs/__probe__')
  return resolved.matched.length > 0
}

const m5bExists = hasM5bRoute()
</script>

<template>
  <table class="w-full border-collapse text-sm">
    <thead>
      <tr class="border-b">
        <th class="text-left py-2 pr-4">Trace UUID</th>
        <th class="text-left py-2 pr-4">Invoked at</th>
        <th class="text-left py-2 pr-4">Account</th>
        <th class="text-left py-2 pr-4">Outcome</th>
        <th class="text-left py-2">Latency (ms)</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="row in props.rows" :key="row.traceUuid" class="border-b hover:bg-gray-50">
        <td class="py-2 pr-4 font-mono text-xs">
          <NuxtLink
            v-if="m5bExists"
            :to="`/ai/observability/runs/${row.traceUuid}`"
            class="link"
          >
            {{ row.traceUuid }}
          </NuxtLink>
          <span v-else>{{ row.traceUuid }}</span>
        </td>
        <td class="py-2 pr-4 text-gray-600">{{ row.invokedAt }}</td>
        <td class="py-2 pr-4 text-gray-600">{{ row.account ?? '—' }}</td>
        <td class="py-2 pr-4">
          <span
            :class="row.outcome === 'ok'
              ? 'text-green-700 bg-green-100'
              : 'text-red-700 bg-red-100'"
            class="px-2 py-0.5 rounded text-xs font-medium"
          >
            {{ row.outcome }}
          </span>
        </td>
        <td class="py-2 text-gray-600">{{ row.latencyMs != null ? row.latencyMs : '—' }}</td>
      </tr>
    </tbody>
  </table>
</template>

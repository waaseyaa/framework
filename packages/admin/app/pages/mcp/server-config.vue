<script setup lang="ts">
const { config, loading, error, fetchConfig } = useMcpServerConfig()
const { t } = useLanguage()

onMounted(fetchConfig)
</script>

<template>
  <div class="p-6">
    <h1 class="text-2xl font-semibold mb-6">{{ t('mcp_server_config_title') }}</h1>

    <div v-if="error" class="alert-error mb-4">{{ error }}</div>
    <div v-if="loading" class="text-gray-500">{{ t('common.loading') }}</div>

    <template v-else-if="config">
      <!-- Transport + protocol banner -->
      <div class="mb-6 p-4 bg-gray-50 rounded border flex gap-8">
        <div>
          <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">
            {{ t('mcp_server_config_transport') }}
          </span>
          <p class="mt-1 font-mono text-sm font-semibold">{{ config.transport }}</p>
        </div>
        <div>
          <span class="text-xs font-medium text-gray-500 uppercase tracking-wide">
            {{ t('mcp_server_config_protocol') }}
          </span>
          <p class="mt-1 font-mono text-sm font-semibold">{{ config.protocolVersion }}</p>
        </div>
      </div>

      <!-- Capabilities -->
      <section class="mb-6">
        <h2 class="text-lg font-medium mb-3">{{ t('mcp_server_config_capabilities') }}</h2>
        <div class="flex flex-wrap gap-2">
          <span
            v-for="cap in config.serverCapabilities"
            :key="cap"
            class="px-3 py-1 text-sm rounded bg-teal-100 text-teal-800 font-medium"
          >
            {{ cap }}
          </span>
          <span v-if="config.serverCapabilities.length === 0" class="text-gray-500 text-sm">—</span>
        </div>
      </section>

      <!-- Registered clients -->
      <section>
        <h2 class="text-lg font-medium mb-3">{{ t('mcp_server_config_clients') }}</h2>
        <table v-if="config.registeredClients.length > 0" class="w-full border-collapse text-sm">
          <thead>
            <tr class="border-b">
              <th class="text-left py-2 pr-4">{{ t('mcp_server_config_client_id') }}</th>
              <th class="text-left py-2 pr-4">{{ t('mcp_server_config_client_fingerprint') }}</th>
              <th class="text-left py-2">{{ t('mcp_server_config_client_last_seen') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="client in config.registeredClients"
              :key="client.clientId"
              class="border-b hover:bg-gray-50"
            >
              <td class="py-2 pr-4 font-mono text-xs">{{ client.clientId }}</td>
              <td class="py-2 pr-4 font-mono text-xs text-gray-600">{{ client.tokenFingerprint }}</td>
              <td class="py-2 text-gray-600">{{ client.lastSeenAt ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="text-gray-500 text-sm">{{ t('mcp_tools_empty') }}</p>
      </section>
    </template>
  </div>
</template>

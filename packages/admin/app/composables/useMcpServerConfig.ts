/**
 * Composable for fetching the MCP server configuration via the admin API (M5C).
 *
 * Security note: the RegisteredClient type intentionally does NOT expose a
 * `token` field — only `tokenFingerprint` (16-char hex) is surfaced.
 */

export interface McpRegisteredClient {
  clientId: string
  addedAt?: string
  lastSeenAt?: string
  /** 16-character hex fingerprint — never the raw token. */
  tokenFingerprint: string
}

export interface McpServerConfig {
  transport: 'streamable-http' | 'sse'
  protocolVersion: string
  registeredClients: McpRegisteredClient[]
  serverCapabilities: string[]
}

interface ServerConfigResponse {
  data: {
    config: McpServerConfig
  }
}

export function useMcpServerConfig() {
  const { apiFetch } = useApi()

  const config = ref<McpServerConfig | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchConfig(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const res = await apiFetch<ServerConfigResponse>('/api/mcp/server-config')
      config.value = res.data?.config ?? null
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to load MCP server config'
    } finally {
      loading.value = false
    }
  }

  return { config, loading, error, fetchConfig }
}

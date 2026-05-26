/**
 * Composable for browsing the MCP tool registry via the admin API (M5C).
 *
 * Exposes a read-only list of registered tools from GET /api/mcp/tools.
 */

export interface McpToolRow {
  name: string
  summary?: string
  requiredCapabilities: string[]
  category?: string
}

interface ToolsResponse {
  data: {
    rows: McpToolRow[]
  }
}

export function useMcpTools() {
  const { apiFetch } = useApi()

  const rows = ref<McpToolRow[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTools(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const res = await apiFetch<ToolsResponse>('/api/mcp/tools')
      rows.value = res.data?.rows ?? []
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to load MCP tools'
    } finally {
      loading.value = false
    }
  }

  return { rows, loading, error, fetchTools }
}

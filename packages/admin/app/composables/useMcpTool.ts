/**
 * Composable for fetching a single MCP tool's detail via the admin API (M5C).
 *
 * URL-encodes the tool name once before the request so names containing
 * dots (e.g. `bimaaji.search_specs`) are safe for path segments.
 */

export interface McpRecentInvocation {
  traceUuid: string
  invokedAt: string
  account?: string
  outcome: 'ok' | 'error'
  errorMessage?: string
  latencyMs?: number
}

export interface McpToolDetail {
  name: string
  summary?: string
  description?: string
  inputSchema: Record<string, unknown>
  requiredCapabilities: string[]
  category?: string
  recentInvocations: McpRecentInvocation[]
}

interface ToolDetailResponse {
  data: {
    tool: McpToolDetail
  }
}

export function useMcpTool() {
  const { apiFetch } = useApi()

  const tool = ref<McpToolDetail | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchTool(name: string): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const encodedName = encodeURIComponent(name)
      const res = await apiFetch<ToolDetailResponse>(`/api/mcp/tools/${encodedName}`)
      tool.value = res.data?.tool ?? null
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to load MCP tool'
    } finally {
      loading.value = false
    }
  }

  return { tool, loading, error, fetchTool }
}

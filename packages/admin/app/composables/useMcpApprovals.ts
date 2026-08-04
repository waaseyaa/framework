/**
 * Composable for the MCP write-tier approval queue (#2177 F1 C1c).
 *
 * Backs the /mcp/approvals operator page against the C1b admin API:
 *   GET  /api/mcp/approvals?limit=&cursor=      → bounded pending page
 *   POST /api/mcp/approvals/{id}/decision       → durable approve/deny
 *
 * Pagination uses only the server's opaque nextCursor, echoed verbatim —
 * never decoded or edited. Previous is a client-side cursor stack. The server
 * stays authoritative for every decision outcome; refusals map to typed kinds
 * so the page can show non-secret messages.
 */

export interface McpApprovalRow {
  id: string
  status: string
  principal: string
  surface: string
  operation: string
  argumentsFingerprint: string
  correlationId: string
  safeArguments: Record<string, unknown>
  requestedAt: string
  expiresAt: string
}

interface ApprovalsResponse {
  data: McpApprovalRow[]
  meta: { limit: number, nextCursor: string | null }
}

export type McpApprovalDecisionResult =
  | { ok: true }
  | { ok: false, kind: 'invalid' | 'forbidden' | 'not-found' | 'conflict' | 'unavailable' | 'network' }

export const MCP_APPROVALS_PAGE_LIMIT = 25

function statusOf(e: unknown): number | null {
  if (typeof e === 'object' && e !== null) {
    const err = e as { statusCode?: unknown, status?: unknown, response?: { status?: unknown } }
    for (const candidate of [err.statusCode, err.status, err.response?.status]) {
      if (typeof candidate === 'number') return candidate
    }
  }
  return null
}

export function useMcpApprovals() {
  const { apiFetch } = useApi()

  const requests = ref<McpApprovalRow[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const forbidden = ref(false)
  const nextCursor = ref<string | null>(null)

  // Cursor of the currently displayed page (null = first page) and the stack
  // of prior page cursors backing Previous. Cursors are opaque server tokens.
  const currentCursor = ref<string | null>(null)
  const previousCursors = ref<(string | null)[]>([])

  const hasNext = computed(() => nextCursor.value !== null)
  const hasPrevious = computed(() => previousCursors.value.length > 0)

  async function fetchPage(cursor: string | null): Promise<boolean> {
    loading.value = true
    error.value = null
    forbidden.value = false
    try {
      const res = await apiFetch<ApprovalsResponse>('/api/mcp/approvals', {
        query: {
          limit: String(MCP_APPROVALS_PAGE_LIMIT),
          ...(cursor !== null ? { cursor } : {}),
        },
      })
      requests.value = res.data ?? []
      nextCursor.value = res.meta?.nextCursor ?? null
      currentCursor.value = cursor
      return true
    } catch (e: unknown) {
      if (statusOf(e) === 403) {
        forbidden.value = true
        requests.value = []
        nextCursor.value = null
      } else {
        // Static, non-secret message — server/adapter text is never echoed.
        error.value = 'Failed to load MCP approval requests.'
      }
      return false
    } finally {
      loading.value = false
    }
  }

  async function fetchFirstPage(): Promise<void> {
    previousCursors.value = []
    await fetchPage(null)
  }

  async function nextPage(): Promise<void> {
    if (nextCursor.value === null) return
    const from = currentCursor.value
    if (await fetchPage(nextCursor.value)) {
      previousCursors.value = [...previousCursors.value, from]
    }
  }

  async function previousPage(): Promise<void> {
    if (previousCursors.value.length === 0) return
    const stack = [...previousCursors.value]
    const target = stack.pop() ?? null
    if (await fetchPage(target)) {
      previousCursors.value = stack
    }
  }

  async function refresh(): Promise<void> {
    await fetchPage(currentCursor.value)
  }

  async function decide(
    id: string,
    decision: 'approve' | 'deny',
    reason?: string,
  ): Promise<McpApprovalDecisionResult> {
    const trimmed = reason?.trim() ?? ''
    try {
      await apiFetch(`/api/mcp/approvals/${id}/decision`, {
        method: 'POST',
        body: { decision, ...(trimmed !== '' ? { reason: trimmed } : {}) },
      })
      return { ok: true }
    } catch (e: unknown) {
      switch (statusOf(e)) {
        case 400: return { ok: false, kind: 'invalid' }
        case 403: return { ok: false, kind: 'forbidden' }
        case 404: return { ok: false, kind: 'not-found' }
        case 409: return { ok: false, kind: 'conflict' }
        case 503: return { ok: false, kind: 'unavailable' }
        default: return { ok: false, kind: 'network' }
      }
    }
  }

  return {
    requests,
    loading,
    error,
    forbidden,
    nextCursor,
    hasNext,
    hasPrevious,
    fetchFirstPage,
    nextPage,
    previousPage,
    refresh,
    decide,
  }
}

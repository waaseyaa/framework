// packages/admin/tests/unit/composables/useMcpApprovals.test.ts
// useMcpApprovals drives the MCP write-tier approval queue (#2177 F1 C1c):
// bounded pages from GET /api/mcp/approvals traversed via the opaque
// nextCursor only, and durable decisions via POST
// /api/mcp/approvals/{id}/decision with deterministic status mapping.
// Mirrors useMcpTools.test.ts (M5C).
import { describe, it, expect, beforeEach } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import { createError, getQuery, readBody, setResponseStatus } from 'h3'

interface SeedRow {
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

function row(id: string, operation: string): SeedRow {
  return {
    id,
    status: 'pending',
    principal: '42',
    surface: 'mcp',
    operation,
    argumentsFingerprint: 'fp_' + operation,
    correlationId: 'corr-' + operation,
    safeArguments: { target: operation + '-target' },
    requestedAt: '2026-08-01T10:00:00+00:00',
    expiresAt: '2026-08-01T11:00:00+00:00',
  }
}

const firstPage = [
  row('apr_' + 'a'.repeat(32), 'node.delete'),
  row('apr_' + 'b'.repeat(32), 'media.purge'),
]
const secondPage = [row('apr_' + 'c'.repeat(32), 'config.wipe')]

// Opaque cursors: the client must echo them verbatim, never decode or edit.
const CURSOR_1 = 'op~AAAA==/weird+chars'
let listStatus = 200
let pagesByCursor: Record<string, { data: SeedRow[]; nextCursor: string | null }> = {}
let listCalls: Array<{ limit: string | undefined; cursor: string | undefined }> = []

registerEndpoint('/api/mcp/approvals', (event) => {
  const query = getQuery(event)
  listCalls.push({
    limit: typeof query.limit === 'string' ? query.limit : undefined,
    cursor: typeof query.cursor === 'string' ? query.cursor : undefined,
  })
  if (listStatus !== 200) {
    throw createError({ statusCode: listStatus, message: 'refused' })
  }
  const key = typeof query.cursor === 'string' ? query.cursor : ''
  const page = pagesByCursor[key] ?? { data: [], nextCursor: null }
  return {
    data: page.data,
    meta: { limit: Number(query.limit ?? 25), nextCursor: page.nextCursor },
  }
})

const DECIDE_ID = 'apr_' + 'a'.repeat(32)
let decideStatus = 204
let decideBodies: Array<Record<string, unknown>> = []

registerEndpoint(`/api/mcp/approvals/${DECIDE_ID}/decision`, {
  method: 'POST',
  handler: async (event) => {
    decideBodies.push(await readBody(event))
    if (decideStatus !== 204) {
      throw createError({ statusCode: decideStatus, message: 'refused' })
    }
    setResponseStatus(event, 204)
    return null
  },
})

beforeEach(() => {
  listStatus = 200
  listCalls = []
  decideStatus = 204
  decideBodies = []
  pagesByCursor = {
    '': { data: firstPage, nextCursor: CURSOR_1 },
    [CURSOR_1]: { data: secondPage, nextCursor: null },
  }
})

describe('useMcpApprovals', () => {
  it('starts empty, not loading, with no error and not forbidden', async () => {
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { requests, loading, error, forbidden, hasNext, hasPrevious } = useMcpApprovals()
    expect(requests.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(forbidden.value).toBe(false)
    expect(hasNext.value).toBe(false)
    expect(hasPrevious.value).toBe(false)
  })

  it('fetches the first bounded page with limit 25 and no cursor, in server order', async () => {
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { requests, fetchFirstPage, hasNext } = useMcpApprovals()
    await fetchFirstPage()
    expect(listCalls).toHaveLength(1)
    expect(listCalls[0]).toEqual({ limit: '25', cursor: undefined })
    expect(requests.value.map(r => r.id)).toEqual(firstPage.map(r => r.id))
    expect(requests.value[0].safeArguments).toEqual({ target: 'node.delete-target' })
    expect(hasNext.value).toBe(true)
  })

  it('traverses forward with the opaque nextCursor echoed verbatim', async () => {
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { requests, fetchFirstPage, nextPage, hasNext, hasPrevious } = useMcpApprovals()
    await fetchFirstPage()
    await nextPage()
    expect(listCalls[1].cursor).toBe(CURSOR_1)
    expect(requests.value.map(r => r.id)).toEqual(secondPage.map(r => r.id))
    expect(hasNext.value).toBe(false)
    expect(hasPrevious.value).toBe(true)
  })

  it('traverses back via the client-side cursor stack without decoding cursors', async () => {
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { requests, fetchFirstPage, nextPage, previousPage, hasPrevious } = useMcpApprovals()
    await fetchFirstPage()
    await nextPage()
    await previousPage()
    // The previous fetch replays the first page's request: no cursor at all.
    expect(listCalls[2].cursor).toBeUndefined()
    expect(requests.value.map(r => r.id)).toEqual(firstPage.map(r => r.id))
    expect(hasPrevious.value).toBe(false)
  })

  it('refresh replays the current page position', async () => {
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { fetchFirstPage, nextPage, refresh } = useMcpApprovals()
    await fetchFirstPage()
    await nextPage()
    await refresh()
    expect(listCalls[2].cursor).toBe(CURSOR_1)
  })

  it('marks forbidden on a 403 view refusal instead of a generic error', async () => {
    listStatus = 403
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { requests, error, forbidden, fetchFirstPage } = useMcpApprovals()
    await fetchFirstPage()
    expect(forbidden.value).toBe(true)
    expect(error.value).toBeNull()
    expect(requests.value).toEqual([])
  })

  it('sets a non-secret error message on a 503 load failure', async () => {
    listStatus = 503
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { error, forbidden, fetchFirstPage } = useMcpApprovals()
    await fetchFirstPage()
    expect(forbidden.value).toBe(false)
    expect(error.value).not.toBeNull()
    expect(error.value).not.toContain('refused')
  })

  it('clears a previous error on a subsequent successful fetch', async () => {
    listStatus = 500
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { error, fetchFirstPage } = useMcpApprovals()
    await fetchFirstPage()
    expect(error.value).not.toBeNull()
    listStatus = 200
    await fetchFirstPage()
    expect(error.value).toBeNull()
  })

  it('decides approve with no reason key when the reason is blank', async () => {
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { decide } = useMcpApprovals()
    const result = await decide(DECIDE_ID, 'approve', '   ')
    expect(result).toEqual({ ok: true })
    expect(decideBodies).toHaveLength(1)
    expect(decideBodies[0]).toEqual({ decision: 'approve' })
  })

  it('decides deny with a trimmed reason', async () => {
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { decide } = useMcpApprovals()
    const result = await decide(DECIDE_ID, 'deny', '  too destructive  ')
    expect(result).toEqual({ ok: true })
    expect(decideBodies[0]).toEqual({ decision: 'deny', reason: 'too destructive' })
  })

  it.each([
    [400, 'invalid'],
    [403, 'forbidden'],
    [404, 'not-found'],
    [409, 'conflict'],
    [503, 'unavailable'],
  ] as const)('maps a %d decision refusal to kind "%s"', async (status, kind) => {
    decideStatus = status
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { decide } = useMcpApprovals()
    const result = await decide(DECIDE_ID, 'approve')
    expect(result).toEqual({ ok: false, kind })
  })

  it('maps an unreachable decision endpoint to kind "network"', async () => {
    const { useMcpApprovals } = await import('~/composables/useMcpApprovals')
    const { decide } = useMcpApprovals()
    const result = await decide('apr_' + 'f'.repeat(32), 'approve')
    expect(result.ok).toBe(false)
    if (!result.ok) {
      expect(['network', 'not-found']).toContain(result.kind)
    }
  })
})

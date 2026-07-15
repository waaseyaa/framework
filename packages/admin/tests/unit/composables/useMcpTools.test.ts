// packages/admin/tests/unit/composables/useMcpTools.test.ts
// useMcpTools fetches GET /api/mcp/tools and exposes rows/loading/error.
// Mirrors useQueueJobs.test.ts (M4B, PR #1529).
import { describe, it, expect, beforeEach } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const seed = {
  name: 'bimaaji.search_specs',
  summary: 'Search the spec index',
  requiredCapabilities: ['read'],
  category: 'bimaaji',
}

let storedRows: typeof seed[] = []
let nextStatus = 200

registerEndpoint('/api/mcp/tools', () => {
  if (nextStatus !== 200) {
    throw createError({ status: nextStatus, message: 'server error' })
  }
  return { data: { rows: storedRows } }
})

beforeEach(() => {
  storedRows = [seed]
  nextStatus = 200
})

describe('useMcpTools', () => {
  it('starts empty with no error and not loading', async () => {
    const { useMcpTools } = await import('~/composables/useMcpTools')
    const { rows, loading, error } = useMcpTools()
    expect(rows.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates rows from /api/mcp/tools', async () => {
    const { useMcpTools } = await import('~/composables/useMcpTools')
    const { rows, loading, error, fetchTools } = useMcpTools()
    await fetchTools()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(rows.value).toHaveLength(1)
    expect(rows.value[0].name).toBe('bimaaji.search_specs')
    expect(rows.value[0].requiredCapabilities).toEqual(['read'])
  })

  it('sets error on fetch failure', async () => {
    nextStatus = 500
    const { useMcpTools } = await import('~/composables/useMcpTools')
    const { rows, error, fetchTools } = useMcpTools()
    await fetchTools()
    expect(rows.value).toEqual([])
    expect(error.value).not.toBeNull()
  })

  it('clears error on subsequent successful fetch', async () => {
    nextStatus = 500
    const { useMcpTools } = await import('~/composables/useMcpTools')
    const { error, fetchTools } = useMcpTools()
    await fetchTools()
    expect(error.value).not.toBeNull()

    nextStatus = 200
    await fetchTools()
    expect(error.value).toBeNull()
  })
})

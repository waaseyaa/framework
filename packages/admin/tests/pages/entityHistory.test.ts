// Per-record History surface (#2419).
//
// The surface must be addressable, scoped to ONE record, and expose nothing at
// all when the server declines it — an empty timeline would itself disclose
// that the record exists and has never been touched.
import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { runActionMock, fetchSchemaMock, schemaRef } = vi.hoisted(() => {
  const { ref } = require('vue') as typeof import('vue')
  return {
    runActionMock: vi.fn(),
    fetchSchemaMock: vi.fn(),
    schemaRef: ref<Record<string, unknown> | null>({ title: 'Content', properties: {} }),
  }
})

mockNuxtImport('useRoute', () => () => ({ params: { entityType: 'node', id: '5' } }))
mockNuxtImport('useAdminConfig', () => () => ({ appName: 'Test admin' }))

vi.mock('~/composables/useLanguage', () => ({
  useLanguage: () => ({
    t: (key: string) => key,
    entityLabel: (_type: string, fallback: string) => fallback,
  }),
}))

vi.mock('~/composables/useSchema', () => ({
  useSchema: () => ({ schema: schemaRef, fetch: fetchSchemaMock }),
}))

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({ runAction: runActionMock }),
}))

const revisions = [
  { revisionId: 3, createdAt: '2026-03-03T00:00:00+00:00', author: 7, log: 'Third pass', isCurrent: false, isLatest: true },
  { revisionId: 2, createdAt: '2026-02-02T00:00:00+00:00', author: null, log: null, isCurrent: false, isLatest: false },
  { revisionId: 1, createdAt: '2026-01-01T00:00:00+00:00', author: 0, log: 'Created', isCurrent: true, isLatest: false },
]

async function mountHistory() {
  const { default: HistoryPage } = await import('~/pages/[entityType]/[id]/history.vue')
  const wrapper = await mountSuspended(HistoryPage, { global: { stubs: { NuxtLink: true } } })
  await flushPromises()
  return wrapper
}

describe('per-record history surface', () => {
  beforeEach(() => {
    runActionMock.mockReset()
    fetchSchemaMock.mockReset()
    fetchSchemaMock.mockResolvedValue(undefined)
  })

  it('asks the server for the history of exactly this record', async () => {
    runActionMock.mockResolvedValue({ entityType: 'node', entityId: '5', revisions })

    await mountHistory()

    expect(runActionMock).toHaveBeenCalledWith('node', 'history', { id: '5' })
  })

  it('renders one timeline entry per revision', async () => {
    runActionMock.mockResolvedValue({ entityType: 'node', entityId: '5', revisions })

    const wrapper = await mountHistory()

    expect(wrapper.findAll('[data-testid="history-timeline"] li')).toHaveLength(3)
    expect(wrapper.text()).toContain('Third pass')
  })

  it('distinguishes the published revision from the latest draft', async () => {
    runActionMock.mockResolvedValue({ entityType: 'node', entityId: '5', revisions })

    const wrapper = await mountHistory()
    const entries = wrapper.findAll('[data-testid="history-timeline"] li')

    expect(entries[0]!.text()).toContain('history_latest')
    expect(entries[0]!.text()).not.toContain('history_current')
    expect(entries[2]!.text()).toContain('history_current')
  })

  it('reports an unattributed revision as unattributed, not as the anonymous account', async () => {
    runActionMock.mockResolvedValue({ entityType: 'node', entityId: '5', revisions })

    const wrapper = await mountHistory()
    const entries = wrapper.findAll('[data-testid="history-timeline"] li')

    expect(entries[1]!.text()).toContain('history_unattributed')
    expect(entries[2]!.text()).toContain('uid:0')
  })

  it('exposes no history at all when the server refuses', async () => {
    runActionMock.mockRejectedValue({ data: { error: { detail: 'You do not have permission to view this entity.' } } })

    const wrapper = await mountHistory()

    expect(wrapper.find('[data-testid="history-timeline"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="history-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="history-unavailable"]').exists()).toBe(true)
  })

  it('distinguishes a genuinely empty history from a refused one', async () => {
    runActionMock.mockResolvedValue({ entityType: 'node', entityId: '5', revisions: [] })

    const wrapper = await mountHistory()

    expect(wrapper.find('[data-testid="history-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="history-unavailable"]').exists()).toBe(false)
  })
})

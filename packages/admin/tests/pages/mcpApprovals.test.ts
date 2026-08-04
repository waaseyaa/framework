// packages/admin/tests/pages/mcpApprovals.test.ts
// /mcp/approvals operator page (#2177 F1 C1c): capability-aware rendering via
// useAdmin().can(), bounded cursor pagination, deliberate decisions through
// the confirmation dialog, and honest stale/error handling.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import { flushPromises } from '@vue/test-utils'
import McpApprovalsPage from '~/pages/mcp/approvals.vue'
import type { McpApprovalRow } from '~/composables/useMcpApprovals'

const HOSTILE = '<img src=x onerror=alert(1)>'

function row(id: string, overrides: Partial<McpApprovalRow> = {}): McpApprovalRow {
  return {
    id,
    status: 'pending',
    principal: '42',
    surface: 'mcp',
    operation: 'node.delete',
    argumentsFingerprint: 'fp_abc',
    correlationId: 'corr-1',
    safeArguments: { target: 'node/7' },
    requestedAt: '2026-08-01T10:00:00+00:00',
    expiresAt: '2026-08-01T11:00:00+00:00',
    ...overrides,
  }
}

const ROW_A = 'apr_' + 'a'.repeat(32)
const ROW_B = 'apr_' + 'b'.repeat(32)

const mocks = vi.hoisted(() => {
  const { ref, computed } = require('vue') as typeof import('vue')
  const requests = ref<unknown[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const forbidden = ref(false)
  const nextCursor = ref<string | null>(null)
  const hasPrevious = ref(false)
  return {
    capabilities: { 'mcp.approval.view': true, 'mcp.approval.decide': true } as Record<string, boolean>,
    requests,
    loading,
    error,
    forbidden,
    nextCursor,
    hasNext: computed(() => nextCursor.value !== null),
    hasPrevious,
    fetchFirstPage: vi.fn(),
    nextPage: vi.fn(),
    previousPage: vi.fn(),
    refresh: vi.fn(),
    decide: vi.fn(),
  }
})

vi.mock('~/composables/useAdmin', () => ({
  useAdmin: () => ({
    can: (permission: string) => mocks.capabilities[permission] === true,
  }),
}))

vi.mock('~/composables/useMcpApprovals', () => ({
  useMcpApprovals: () => ({
    requests: mocks.requests,
    loading: mocks.loading,
    error: mocks.error,
    forbidden: mocks.forbidden,
    nextCursor: mocks.nextCursor,
    hasNext: mocks.hasNext,
    hasPrevious: mocks.hasPrevious,
    fetchFirstPage: mocks.fetchFirstPage,
    nextPage: mocks.nextPage,
    previousPage: mocks.previousPage,
    refresh: mocks.refresh,
    decide: mocks.decide,
  }),
}))

beforeEach(() => {
  mocks.capabilities = { 'mcp.approval.view': true, 'mcp.approval.decide': true }
  mocks.requests.value = []
  mocks.loading.value = false
  mocks.error.value = null
  mocks.forbidden.value = false
  mocks.nextCursor.value = null
  mocks.hasPrevious.value = false
  mocks.fetchFirstPage.mockReset().mockResolvedValue(undefined)
  mocks.nextPage.mockReset().mockResolvedValue(undefined)
  mocks.previousPage.mockReset().mockResolvedValue(undefined)
  mocks.refresh.mockReset().mockResolvedValue(undefined)
  mocks.decide.mockReset().mockResolvedValue({ ok: true })
})

describe('/mcp/approvals page', () => {
  it('shows an access notice and never fetches without the view capability', async () => {
    mocks.capabilities = {}
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(wrapper.find('[data-testid="approvals-forbidden"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="approvals-table"]').exists()).toBe(false)
    expect(mocks.fetchFirstPage).not.toHaveBeenCalled()
  })

  it('fetches the first page on mount when view is granted', async () => {
    await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(mocks.fetchFirstPage).toHaveBeenCalledTimes(1)
  })

  it('shows the loading state', async () => {
    mocks.loading.value = true
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    expect(wrapper.find('[data-testid="approvals-loading"]').exists()).toBe(true)
  })

  it('shows the empty state when no requests are pending', async () => {
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(wrapper.find('[data-testid="approvals-empty"]').exists()).toBe(true)
  })

  it('shows a non-secret error state on load failure', async () => {
    mocks.error.value = 'Failed to load MCP approval requests.'
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    expect(wrapper.find('[data-testid="approvals-error"]').text()).toContain('Failed to load')
  })

  it('shows the forbidden state when the server refuses the view mid-session', async () => {
    mocks.forbidden.value = true
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    expect(wrapper.find('[data-testid="approvals-forbidden"]').exists()).toBe(true)
  })

  it('renders rows in server order with safe projections', async () => {
    mocks.requests.value = [row(ROW_A), row(ROW_B, { operation: 'media.purge' })]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    const rows = wrapper.findAll('[data-testid="approval-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain(ROW_A)
    expect(rows[0].text()).toContain('node.delete')
    expect(rows[0].text()).toContain('mcp')
    expect(rows[0].text()).toContain('node/7')
    expect(rows[1].text()).toContain('media.purge')
  })

  it('renders hostile server strings as text, never as markup', async () => {
    mocks.requests.value = [row(ROW_A, { operation: HOSTILE, safeArguments: { x: HOSTILE } })]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    expect(wrapper.text()).toContain(HOSTILE)
    expect(wrapper.find('[data-testid="approvals-table"] img').exists()).toBe(false)
  })

  it('hides decision actions entirely for a view-only operator', async () => {
    mocks.capabilities = { 'mcp.approval.view': true }
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    expect(wrapper.findAll('[data-testid="approval-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="approval-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="approval-deny"]').exists()).toBe(false)
  })

  it('enables decision actions for a deciding operator', async () => {
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    expect(wrapper.find('[data-testid="approval-approve"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="approval-deny"]').exists()).toBe(true)
  })

  it('wires pagination to the composable without touching cursors', async () => {
    mocks.requests.value = [row(ROW_A)]
    mocks.nextCursor.value = 'opaque'
    mocks.hasPrevious.value = true
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approvals-next"]').trigger('click')
    await wrapper.get('[data-testid="approvals-previous"]').trigger('click')
    expect(mocks.nextPage).toHaveBeenCalledTimes(1)
    expect(mocks.previousPage).toHaveBeenCalledTimes(1)
  })

  it('disables pagination buttons at the boundaries', async () => {
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    expect(wrapper.get('[data-testid="approvals-next"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="approvals-previous"]').attributes('disabled')).toBeDefined()
  })

  it('offers a manual refresh', async () => {
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approvals-refresh"]').trigger('click')
    expect(mocks.refresh).toHaveBeenCalledTimes(1)
  })

  it('decides through the confirmation dialog and refetches on success', async () => {
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approval-deny"]').trigger('click')
    await wrapper.get('[data-testid="approval-decision-reason"]').setValue('too risky')
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.decide).toHaveBeenCalledWith(ROW_A, 'deny', 'too risky')
    expect(mocks.refresh).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-testid="approvals-notice"]').text()).toContain('Decision recorded')
  })

  it('does not decide when the dialog is cancelled', async () => {
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approval-approve"]').trigger('click')
    await wrapper.get('[data-testid="approval-decision-cancel"]').trigger('click')
    await flushPromises()
    expect(mocks.decide).not.toHaveBeenCalled()
  })

  it('treats a 409 conflict as stale data: notice plus refetch, no pretend success', async () => {
    mocks.decide.mockResolvedValue({ ok: false, kind: 'conflict' })
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approval-approve"]').trigger('click')
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.refresh).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="approvals-notice"]').text()).toContain('no longer pending')
    expect(wrapper.text()).not.toContain('Decision recorded')
  })

  it('treats a 404 as stale data: notice plus refetch', async () => {
    mocks.decide.mockResolvedValue({ ok: false, kind: 'not-found' })
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approval-approve"]').trigger('click')
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.refresh).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="approvals-notice"]').text()).toContain('no longer pending')
  })

  it.each([
    ['invalid', 'rejected the decision as invalid'],
    ['forbidden', 'not allowed to decide'],
    ['unavailable', 'approval store is unavailable'],
    ['network', 'Could not reach the server'],
  ] as const)('surfaces a clear message for a "%s" refusal without refetching', async (kind, fragment) => {
    mocks.decide.mockResolvedValue({ ok: false, kind })
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approval-approve"]').trigger('click')
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.refresh).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="approvals-notice"]').text()).toContain(fragment)
  })

  it('prevents double submission while a decision is in flight', async () => {
    let resolveDecide: (v: unknown) => void = () => {}
    mocks.decide.mockImplementation(() => new Promise((resolve) => { resolveDecide = resolve }))
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approval-approve"]').trigger('click')
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    resolveDecide({ ok: true })
    await flushPromises()
    expect(mocks.decide).toHaveBeenCalledTimes(1)
  })

  it('announces queue status through a live region', async () => {
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    await wrapper.get('[data-testid="approval-approve"]').trigger('click')
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="approvals-notice"]').attributes('aria-live')).toBe('polite')
  })

  it('announces load errors assertively', async () => {
    mocks.error.value = 'Failed to load MCP approval requests.'
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    expect(wrapper.get('[data-testid="approvals-error"]').attributes('role')).toBe('alert')
  })

  it('exposes the scrollable table as a labelled keyboard-focusable region', async () => {
    mocks.requests.value = [row(ROW_A)]
    const wrapper = await mountSuspended(McpApprovalsPage, { global: { stubs: { teleport: true } } })
    const region = wrapper.get('[data-testid="approvals-region"]')
    expect(region.attributes('role')).toBe('region')
    expect(region.attributes('tabindex')).toBe('0')
    expect(region.attributes('aria-label')).toBeTruthy()
  })

  describe('focus restoration', () => {
    async function mountAttached() {
      return await mountSuspended(McpApprovalsPage, {
        attachTo: document.body,
        global: { stubs: { teleport: true } },
      })
    }

    it('returns focus to the triggering control after cancel', async () => {
      mocks.requests.value = [row(ROW_A)]
      const wrapper = await mountAttached()
      const trigger = wrapper.get('[data-testid="approval-approve"]').element as HTMLElement
      trigger.focus()
      await wrapper.get('[data-testid="approval-approve"]').trigger('click')
      await wrapper.get('[data-testid="approval-decision-cancel"]').trigger('click')
      await flushPromises()
      expect(document.activeElement).toBe(trigger)
      wrapper.unmount()
    })

    it('returns focus to the triggering control after a successful decision', async () => {
      mocks.requests.value = [row(ROW_A)]
      const wrapper = await mountAttached()
      const trigger = wrapper.get('[data-testid="approval-deny"]').element as HTMLElement
      trigger.focus()
      await wrapper.get('[data-testid="approval-deny"]').trigger('click')
      await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
      await flushPromises()
      expect(document.activeElement).toBe(trigger)
      wrapper.unmount()
    })

    it('returns focus to the triggering control after a refusal', async () => {
      mocks.decide.mockResolvedValue({ ok: false, kind: 'unavailable' })
      mocks.requests.value = [row(ROW_A)]
      const wrapper = await mountAttached()
      const trigger = wrapper.get('[data-testid="approval-approve"]').element as HTMLElement
      trigger.focus()
      await wrapper.get('[data-testid="approval-approve"]').trigger('click')
      await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
      await flushPromises()
      expect(document.activeElement).toBe(trigger)
      wrapper.unmount()
    })

    it('falls back to the refresh control when the trigger row is gone after refetch', async () => {
      mocks.requests.value = [row(ROW_A)]
      mocks.refresh.mockImplementation(async () => {
        mocks.requests.value = []
      })
      const wrapper = await mountAttached()
      const trigger = wrapper.get('[data-testid="approval-approve"]').element as HTMLElement
      trigger.focus()
      await wrapper.get('[data-testid="approval-approve"]').trigger('click')
      await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
      await flushPromises()
      expect(document.activeElement?.getAttribute('data-testid')).toBe('approvals-refresh')
      wrapper.unmount()
    })
  })
})

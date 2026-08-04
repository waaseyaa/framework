// packages/admin/tests/components/mcp/ApprovalDecisionDialog.test.ts
// Deliberate approve/deny confirmation for the MCP approval queue (#2177 F1
// C1c): shows the operation and safe arguments as text, takes an optional
// bounded reason, and guards against double submission.
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import ApprovalDecisionDialog from '~/components/mcp/ApprovalDecisionDialog.vue'
import type { McpApprovalRow } from '~/composables/useMcpApprovals'

const HOSTILE = '<img src=x onerror=alert(1)><script>alert(2)</script>'

function request(overrides: Partial<McpApprovalRow> = {}): McpApprovalRow {
  return {
    id: 'apr_' + 'a'.repeat(32),
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

async function mount(props: Record<string, unknown> = {}) {
  return await mountSuspended(ApprovalDecisionDialog, {
    props: {
      open: true,
      decision: 'approve',
      request: request(),
      submitting: false,
      ...props,
    },
    global: { stubs: { teleport: true } },
  })
}

describe('ApprovalDecisionDialog', () => {
  it('renders an application modal showing the operation and safe arguments', async () => {
    const wrapper = await mount()
    const dialog = wrapper.find('[role="alertdialog"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.attributes('aria-labelledby')).toBeTruthy()
    expect(wrapper.text()).toContain('node.delete')
    expect(wrapper.text()).toContain('node/7')
    expect(wrapper.text()).toContain('mcp')
  })

  it('renders hostile server strings as text, never as markup', async () => {
    const wrapper = await mount({
      request: request({ operation: HOSTILE, safeArguments: { payload: HOSTILE } }),
    })
    expect(wrapper.text()).toContain(HOSTILE)
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('<script>')
  })

  it('emits confirm with the entered reason', async () => {
    const wrapper = await mount()
    await wrapper.get('[data-testid="approval-decision-reason"]').setValue('cleared with owner')
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    expect(wrapper.emitted('confirm')).toEqual([['cleared with owner']])
  })

  it('emits cancel and never confirm from the cancel button', async () => {
    const wrapper = await mount()
    await wrapper.get('[data-testid="approval-decision-cancel"]').trigger('click')
    expect(wrapper.emitted('cancel')).toHaveLength(1)
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('accepts a reason of exactly 500 Unicode characters', async () => {
    const wrapper = await mount()
    // 500 astral code points — 1000 UTF-16 units; the bound counts characters.
    await wrapper.get('[data-testid="approval-decision-reason"]').setValue('🙂'.repeat(500))
    expect(wrapper.get('[data-testid="approval-decision-confirm"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).toContain('0')
  })

  it('rejects a reason of 501 Unicode characters and disables confirm', async () => {
    const wrapper = await mount()
    await wrapper.get('[data-testid="approval-decision-reason"]').setValue('🙂'.repeat(501))
    expect(wrapper.get('[data-testid="approval-decision-confirm"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="approval-decision-reason-error"]').exists()).toBe(true)
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('rejects a multi-line reason and disables confirm', async () => {
    const wrapper = await mount()
    await wrapper.get('[data-testid="approval-decision-reason"]').setValue('line one\nline two')
    expect(wrapper.get('[data-testid="approval-decision-confirm"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="approval-decision-reason-error"]').exists()).toBe(true)
  })

  it('shows the remaining character count', async () => {
    const wrapper = await mount()
    await wrapper.get('[data-testid="approval-decision-reason"]').setValue('abc')
    expect(wrapper.text()).toContain('497')
  })

  it('disables confirm while submitting so a decision cannot double-submit', async () => {
    const wrapper = await mount({ submitting: true })
    expect(wrapper.get('[data-testid="approval-decision-confirm"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="approval-decision-confirm"]').trigger('click')
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('labels the confirm action by decision', async () => {
    const approve = await mount({ decision: 'approve' })
    expect(approve.get('[data-testid="approval-decision-confirm"]').text()).toBe('Approve')
    const deny = await mount({ decision: 'deny' })
    expect(deny.get('[data-testid="approval-decision-confirm"]').text()).toBe('Deny')
  })

  it('renders nothing when closed', async () => {
    const wrapper = await mount({ open: false })
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false)
  })

  describe('focus management', () => {
    async function mountAttached(props: Record<string, unknown> = {}) {
      return await mountSuspended(ApprovalDecisionDialog, {
        props: {
          open: false,
          decision: 'approve' as const,
          request: request(),
          submitting: false,
          ...props,
        },
        attachTo: document.body,
        global: { stubs: { teleport: true } },
      })
    }

    it('moves focus into the dialog (cancel button) when opened', async () => {
      const wrapper = await mountAttached()
      await wrapper.setProps({ open: true })
      await nextTick()
      await nextTick()
      expect(document.activeElement?.getAttribute('data-testid')).toBe('approval-decision-cancel')
      wrapper.unmount()
    })

    it('wraps Tab from the last focusable control back to the first', async () => {
      const wrapper = await mountAttached()
      await wrapper.setProps({ open: true })
      await nextTick()
      const confirm = wrapper.get('[data-testid="approval-decision-confirm"]').element as HTMLElement
      confirm.focus()
      await wrapper.get('[role="alertdialog"]').trigger('keydown', { key: 'Tab' })
      expect((document.activeElement as HTMLElement).id).toBe('approval-decision-reason')
      wrapper.unmount()
    })

    it('wraps Shift+Tab from the first focusable control to the last', async () => {
      const wrapper = await mountAttached()
      await wrapper.setProps({ open: true })
      await nextTick()
      const reasonInput = wrapper.get('[data-testid="approval-decision-reason"]').element as HTMLElement
      reasonInput.focus()
      await wrapper.get('[role="alertdialog"]').trigger('keydown', { key: 'Tab', shiftKey: true })
      expect(document.activeElement?.getAttribute('data-testid')).toBe('approval-decision-confirm')
      wrapper.unmount()
    })

    it('emits cancel on Escape while idle', async () => {
      const wrapper = await mountAttached()
      await wrapper.setProps({ open: true })
      await nextTick()
      await wrapper.get('[role="alertdialog"]').trigger('keydown', { key: 'Escape' })
      expect(wrapper.emitted('cancel')).toHaveLength(1)
      wrapper.unmount()
    })

    it('ignores Escape, overlay click, and the cancel button while submitting', async () => {
      const wrapper = await mountAttached({ submitting: true })
      await wrapper.setProps({ open: true })
      await nextTick()
      await wrapper.get('[role="alertdialog"]').trigger('keydown', { key: 'Escape' })
      await wrapper.get('.confirm-overlay').trigger('click')
      await wrapper.get('[data-testid="approval-decision-cancel"]').trigger('click')
      expect(wrapper.emitted('cancel')).toBeUndefined()
      wrapper.unmount()
    })
  })
})

// packages/admin/tests/unit/composables/useAdmin.test.ts
import { describe, it, expect, vi } from 'vitest'
import { useAdmin } from '~/composables/useAdmin'
import { ADMIN_RUNTIME_UNAVAILABLE_MESSAGE } from '~/composables/useAdminRuntime'

describe('useAdmin', () => {
  it('returns catalog from runtime', () => {
    const { catalog } = useAdmin()
    expect(catalog.length).toBeGreaterThan(0)
    expect(catalog[0].id).toBe('user')
  })

  it('returns tenant from runtime', () => {
    const { tenant } = useAdmin()
    expect(tenant.name).toBe('Waaseyaa')
  })

  it('returns ui with header and sidebar arrays from runtime', () => {
    const { ui } = useAdmin()
    expect(ui.headerLinks).toEqual([])
    expect(ui.sidebarItems).toEqual([])
  })

  it('hasCapability returns true for existing capability', () => {
    const { hasCapability } = useAdmin()
    expect(hasCapability('node', 'create')).toBe(true)
  })

  it('hasCapability returns false for unknown entity type', () => {
    const { hasCapability } = useAdmin()
    expect(hasCapability('nonexistent', 'list')).toBe(false)
  })

  it('getEntity returns CatalogEntry by type id', () => {
    const { getEntity } = useAdmin()
    expect(getEntity('node')?.label).toBe('Content')
  })

  it('getEntity returns undefined for unknown type', () => {
    const { getEntity } = useAdmin()
    expect(getEntity('nonexistent')).toBeUndefined()
  })

  it('can() returns true only for a capability the server projected as exactly true', () => {
    const { can } = useAdmin()
    expect(can('mcp.approval.view')).toBe(true)
  })

  it('can() returns false for a capability the server projected as false', () => {
    const { can } = useAdmin()
    expect(can('mcp.approval.decide')).toBe(false)
  })

  it('can() fails closed for permissions the server never projected', () => {
    const { can } = useAdmin()
    expect(can('administer users')).toBe(false)
  })

  it('can() fails closed for truthy non-boolean projection values', () => {
    const { can } = useAdmin()
    expect(can('hostile.truthy')).toBe(false)
  })

  it('exposes the raw capabilities projection from the runtime', () => {
    const { capabilities } = useAdmin()
    expect(capabilities?.['mcp.approval.view']).toBe(true)
  })

  it('throws an explicit invariant error when admin runtime is unavailable', async () => {
    vi.resetModules()
    vi.doMock('~/composables/useAdminRuntime', () => ({
      ADMIN_RUNTIME_UNAVAILABLE_MESSAGE,
      requireAdminRuntime: () => {
        throw new Error(ADMIN_RUNTIME_UNAVAILABLE_MESSAGE)
      },
    }))

    const { useAdmin } = await import('~/composables/useAdmin')
    expect(() => useAdmin()).toThrowError(ADMIN_RUNTIME_UNAVAILABLE_MESSAGE)
  })
})

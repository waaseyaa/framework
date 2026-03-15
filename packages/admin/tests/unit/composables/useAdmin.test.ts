// packages/admin/tests/unit/composables/useAdmin.test.ts
import { describe, it, expect } from 'vitest'
import { useAdmin } from '~/composables/useAdmin'

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
})

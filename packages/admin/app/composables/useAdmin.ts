import type { CatalogEntry, CatalogCapabilities } from '../contracts/catalog'
import type { AdminTenant } from '../contracts/auth'
import type { AdminRuntime } from '../contracts/runtime'
import { requireAdminRuntime } from './useAdminRuntime'

export function useAdmin(): {
  catalog: CatalogEntry[]
  tenant: AdminTenant
  features?: Record<string, boolean>
  capabilities?: Record<string, boolean>
  ui: AdminRuntime['ui']
  hasCapability: (entityType: string, cap: keyof CatalogCapabilities) => boolean
  can: (permission: string) => boolean
  getEntity: (type: string) => CatalogEntry | undefined
} {
  const $admin = requireAdminRuntime()

  function hasCapability(entityType: string, cap: keyof CatalogCapabilities): boolean {
    const entry = $admin.catalog.find(e => e.id === entityType)
    return entry?.capabilities[cap] ?? false
  }

  /**
   * Canonical session-capability check for pages and navigation.
   *
   * Fail-closed: true only when the server projected this exact permission as
   * boolean `true` (missing map, unknown key, or non-boolean value → false).
   * The projection is host-allowlisted and server-authoritative; never fall
   * back to roles. Server middleware remains the enforcement boundary.
   */
  function can(permission: string): boolean {
    return $admin.capabilities?.[permission] === true
  }

  function getEntity(type: string): CatalogEntry | undefined {
    return $admin.catalog.find(e => e.id === type)
  }

  return {
    catalog: $admin.catalog,
    tenant: $admin.tenant,
    features: $admin.features,
    capabilities: $admin.capabilities,
    ui: $admin.ui,
    hasCapability,
    can,
    getEntity,
  }
}

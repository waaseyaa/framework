import type { AdminRuntime } from '../contracts/runtime'
import type { CatalogEntry, CatalogCapabilities } from '../contracts/catalog'
import type { AdminTenant } from '../contracts/auth'
import type { AdminBootstrap } from '../contracts/bootstrap'

export function useAdmin(): {
  bootstrap: AdminBootstrap
  catalog: CatalogEntry[]
  tenant: AdminTenant
  hasCapability: (entityType: string, cap: keyof CatalogCapabilities) => boolean
  getEntity: (type: string) => CatalogEntry | undefined
} {
  const { $admin } = useNuxtApp() as { $admin: AdminRuntime }

  function hasCapability(entityType: string, cap: keyof CatalogCapabilities): boolean {
    const entry = $admin.catalog.find(e => e.id === entityType)
    return entry?.capabilities[cap] ?? false
  }

  function getEntity(type: string): CatalogEntry | undefined {
    return $admin.catalog.find(e => e.id === type)
  }

  return {
    bootstrap: $admin.bootstrap,
    catalog: $admin.catalog,
    tenant: $admin.tenant,
    hasCapability,
    getEntity,
  }
}

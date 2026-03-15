import type { AdminBootstrap } from './bootstrap'
import type { AuthAdapter } from './auth'
import type { TransportAdapter } from './transport'
import type { CatalogEntry } from './catalog'
import type { AdminTenant } from './auth'

export interface AdminRuntime {
  bootstrap: AdminBootstrap
  auth: AuthAdapter
  transport: TransportAdapter
  catalog: CatalogEntry[]
  tenant: AdminTenant
}

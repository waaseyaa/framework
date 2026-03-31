import type { AuthAdapter } from './auth'
import type { TransportAdapter } from './transport'
import type { CatalogEntry } from './catalog'
import type { AdminTenant, AdminAccount } from './auth'

export interface AdminAuthConfig {
  strategy: 'redirect' | 'embedded'
  loginUrl: string
}

export interface AdminRuntime {
  auth: AuthAdapter
  authConfig: AdminAuthConfig
  transport: TransportAdapter
  catalog: CatalogEntry[]
  tenant: AdminTenant
  account: AdminAccount
  features?: Record<string, boolean>
}

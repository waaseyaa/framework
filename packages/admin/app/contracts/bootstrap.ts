import type { AdminAccount, AdminTenant } from './auth'
import type { CatalogEntry } from './catalog'
export interface AdminBootstrap {
  version: string
  auth: AdminAuthConfig
  account: AdminAccount
  tenant: AdminTenant
  transport: AdminTransportConfig
  entities: CatalogEntry[]
  features?: Record<string, boolean>
}

export interface AdminAuthConfig {
  strategy: 'redirect' | 'embedded'
  loginUrl?: string
  loginEndpoint?: string
  logoutEndpoint?: string
}

export interface AdminTransportConfig {
  strategy: 'jsonapi' | 'custom'
  apiPath?: string
}

declare global {
  interface Window {
    __WAASEYAA_ADMIN__?: AdminBootstrap
  }
}

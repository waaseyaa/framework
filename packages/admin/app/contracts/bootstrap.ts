import type { AdminAccount, AdminTenant } from './auth'
import type { CatalogEntry } from './catalog'
import { ADMIN_CONTRACT_VERSION } from './version'

export interface AdminBootstrap {
  version: typeof ADMIN_CONTRACT_VERSION
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

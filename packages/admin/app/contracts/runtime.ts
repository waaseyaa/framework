import type { AuthAdapter, AdminTenant, AdminAccount  } from './auth'
import type { TransportAdapter } from './transport'
import type { CatalogEntry } from './catalog'
import type { AdminSurfaceHeaderLink, AdminSurfaceSidebarItem } from './surface-ui'

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
  /** Resolved from session `ui`; always defined (defaults to empty arrays). */
  ui: {
    headerLinks: AdminSurfaceHeaderLink[]
    sidebarItems: AdminSurfaceSidebarItem[]
    navigationMode: 'full' | 'catalog-only'
  }
}

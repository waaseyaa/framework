import type { AdminSurfaceAccount, AdminSurfaceTenant } from './adminSurface'

export interface AuthAdapter {
  getSession(): Promise<AdminSession | null>
  refreshSession?(): Promise<AdminSession | null>
  logout(): Promise<void>
  getLoginUrl(returnTo: string): string
}

export interface AdminSession {
  account: AdminAccount
  tenant: AdminTenant
  features?: Record<string, boolean>
}

export type AdminAccount = AdminSurfaceAccount & {
  emailVerified?: boolean
}

export type AdminTenant = AdminSurfaceTenant & {
  scopingStrategy: 'server' | 'header'
}

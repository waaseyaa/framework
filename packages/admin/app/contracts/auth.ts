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

export interface AdminAccount {
  id: string
  name: string
  email?: string
  emailVerified?: boolean
  roles: string[]
}

export interface AdminTenant {
  id: string
  name: string
  scopingStrategy: 'server' | 'header'
}

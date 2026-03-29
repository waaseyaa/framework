import type { AuthAdapter, AdminSession, AdminAccount, AdminTenant } from '../contracts/auth'
import type { AdminAuthConfig } from '../contracts/runtime'

export class SessionAuthAdapter implements AuthAdapter {
  constructor(
    private readonly account: AdminAccount,
    private readonly tenant: AdminTenant,
    private readonly authConfig: AdminAuthConfig,
    private readonly features?: Record<string, boolean>,
  ) {}

  async getSession(): Promise<AdminSession | null> {
    return {
      account: this.account,
      tenant: this.tenant,
      features: this.features,
    }
  }

  async refreshSession(): Promise<AdminSession | null> {
    return null
  }

  async logout(): Promise<void> {
    // Redirect-based auth — logout handled server-side
  }

  getLoginUrl(returnTo: string): string {
    const loginUrl = this.authConfig.loginUrl
    const separator = loginUrl.includes('?') ? '&' : '?'
    return `${loginUrl}${separator}returnTo=${encodeURIComponent(returnTo)}`
  }
}

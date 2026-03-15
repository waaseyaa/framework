import type { AuthAdapter, AdminSession } from '../contracts/auth'
import type { AdminBootstrap } from '../contracts/bootstrap'

export class BootstrapAuthAdapter implements AuthAdapter {
  constructor(
    private readonly bootstrap: AdminBootstrap,
    private readonly fetchFn: typeof fetch = fetch,
  ) {}

  async getSession(): Promise<AdminSession | null> {
    return {
      account: this.bootstrap.account,
      tenant: this.bootstrap.tenant,
      features: this.bootstrap.features,
    }
  }

  async refreshSession(): Promise<AdminSession | null> {
    return null
  }

  async logout(): Promise<void> {
    const endpoint = this.bootstrap.auth.logoutEndpoint
    if (endpoint) {
      await this.fetchFn(endpoint, { method: 'POST' })
    }
  }

  getLoginUrl(returnTo: string): string {
    const loginUrl = this.bootstrap.auth.loginUrl ?? '/login'
    const separator = loginUrl.includes('?') ? '&' : '?'
    return `${loginUrl}${separator}returnTo=${encodeURIComponent(returnTo)}`
  }
}

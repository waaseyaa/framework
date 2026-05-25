/**
 * Composable for managing OIDC client registrations via the admin API (WP05).
 *
 * Exposes CRUD + secret regeneration for /api/oidc-clients.
 * The client_secret field is only present on create/regenerate responses;
 * it is absent from all other responses.
 */

export interface OidcClient {
  id: string
  client_id: string
  name: string
  redirect_uris: string[]
  scopes: string[]
  grant_types: string[]
  is_confidential: boolean
  /** Only present on create or regenerateSecret responses. */
  client_secret?: string
}

export interface OidcClientInput {
  name?: string
  client_id?: string
  redirect_uris?: string[]
  scopes?: string[]
  grant_types?: string[]
  is_confidential?: boolean
}

interface ListResponse {
  data: OidcClient[]
}

interface SingleResponse {
  data: OidcClient
}

export function useOidcClients() {
  const { apiFetch } = useApi()

  async function list(): Promise<OidcClient[]> {
    const res = await apiFetch<ListResponse>('/api/oidc-clients')
    return res.data ?? []
  }

  async function get(id: string): Promise<OidcClient> {
    const res = await apiFetch<SingleResponse>(`/api/oidc-clients/${id}`)
    return res.data
  }

  async function create(input: OidcClientInput): Promise<OidcClient> {
    const res = await apiFetch<SingleResponse>('/api/oidc-clients', {
      method: 'POST',
      body: input,
    })
    return res.data
  }

  async function update(id: string, input: OidcClientInput): Promise<OidcClient> {
    const res = await apiFetch<SingleResponse>(`/api/oidc-clients/${id}`, {
      method: 'PATCH',
      body: input,
    })
    return res.data
  }

  async function remove(id: string): Promise<void> {
    await apiFetch<void>(`/api/oidc-clients/${id}`, { method: 'DELETE' })
  }

  async function regenerateSecret(id: string): Promise<OidcClient> {
    const res = await apiFetch<SingleResponse>(`/api/oidc-clients/${id}/regenerate-secret`, {
      method: 'POST',
    })
    return res.data
  }

  return { list, get, create, update, remove, regenerateSecret }
}

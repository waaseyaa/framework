import type { TransportAdapter, ListQuery, ListResult, EntityResource } from '../contracts/transport'
import { TransportError } from '../contracts/transport'
import type { EntitySchema } from '../contracts/schema'
import type { AdminTenant } from '../contracts/auth'

export class JsonApiTransportAdapter implements TransportAdapter {
  constructor(
    private readonly apiPath: string,
    private readonly tenant: AdminTenant,
    private readonly fetchFn: typeof fetch = fetch,
  ) {}

  async list(type: string, query?: ListQuery): Promise<ListResult> {
    const params = new URLSearchParams()
    if (query?.page) {
      params.set('page[offset]', String(query.page.offset))
      params.set('page[limit]', String(query.page.limit))
    }
    if (query?.sort) {
      params.set('sort', query.sort)
    }
    if (query?.filter) {
      for (const [field, cond] of Object.entries(query.filter)) {
        params.set(`filter[${field}][operator]`, cond.operator)
        params.set(`filter[${field}][value]`, cond.value)
      }
    }
    const qs = params.toString()
    const url = `${this.apiPath}/${type}${qs ? '?' + qs : ''}`
    const json = await this.request(url, { method: 'GET' })
    const data = (Array.isArray(json.data) ? json.data : []).map(this.normalizeResource)
    return {
      data,
      meta: {
        total: json.meta?.total ?? 0,
        offset: json.meta?.offset ?? 0,
        limit: json.meta?.limit ?? 25,
      },
    }
  }

  async get(type: string, id: string): Promise<EntityResource> {
    const json = await this.request(`${this.apiPath}/${type}/${id}`, { method: 'GET' })
    return this.normalizeResource(json.data)
  }

  async create(type: string, attributes: Record<string, any>): Promise<EntityResource> {
    const json = await this.request(`${this.apiPath}/${type}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: JSON.stringify({ data: { type, attributes } }),
    })
    return this.normalizeResource(json.data)
  }

  async update(type: string, id: string, attributes: Record<string, any>): Promise<EntityResource> {
    const json = await this.request(`${this.apiPath}/${type}/${id}`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/vnd.api+json' },
      body: JSON.stringify({ data: { type, id, attributes } }),
    })
    return this.normalizeResource(json.data)
  }

  async remove(type: string, id: string): Promise<void> {
    await this.request(`${this.apiPath}/${type}/${id}`, { method: 'DELETE' })
  }

  async schema(type: string): Promise<EntitySchema> {
    const json = await this.request(`${this.apiPath}/schema/${type}`, { method: 'GET' })
    return json.meta.schema
  }

  async search(type: string, field: string, query: string, limit: number = 10): Promise<EntityResource[]> {
    if (query.length < 2) return []
    const params = new URLSearchParams()
    params.set(`filter[${field}][operator]`, 'STARTS_WITH')
    params.set(`filter[${field}][value]`, query)
    params.set('page[limit]', String(limit))
    params.set('sort', field)
    const url = `${this.apiPath}/${type}?${params.toString()}`
    const json = await this.request(url, { method: 'GET' })
    return (Array.isArray(json.data) ? json.data : []).map(this.normalizeResource)
  }

  private async request(url: string, init: RequestInit): Promise<any> {
    const headers: Record<string, string> = {
      Accept: 'application/vnd.api+json',
      ...(init.headers as Record<string, string> ?? {}),
    }
    if (this.tenant.scopingStrategy === 'header') {
      headers['X-Tenant-Id'] = this.tenant.id
    }
    const response = await this.fetchFn(url, { ...init, headers })
    if (response.status === 204) return null
    const json = await response.json()
    if (!response.ok) {
      const error = json.errors?.[0] ?? {}
      throw new TransportError(
        response.status,
        error.title ?? `HTTP ${response.status}`,
        error.detail,
        error.source,
      )
    }
    return json
  }

  private normalizeResource(resource: any): EntityResource {
    return {
      type: resource.type,
      id: resource.id,
      attributes: resource.attributes ?? {},
    }
  }
}

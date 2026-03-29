import type { TransportAdapter, ListQuery, ListResult, EntityResource } from '../contracts/transport'
import { TransportError } from '../contracts/transport'
import type { EntitySchema } from '../contracts/schema'

/**
 * Surface API response envelope.
 */
interface SurfaceResult<T> {
  ok: boolean
  data?: T
  error?: { status: number; title: string; detail?: string; source?: Record<string, string> }
  meta?: Record<string, unknown>
}

interface SurfaceEntity {
  type: string
  id: string
  attributes: Record<string, unknown>
}

interface SurfaceListResult {
  entities: SurfaceEntity[]
  total: number
  offset: number
  limit: number
}

/**
 * Transport adapter that talks to the /admin/surface/* endpoints.
 *
 * Replaces JsonApiTransportAdapter when the host supports the admin surface contract.
 */
export class AdminSurfaceTransportAdapter implements TransportAdapter {
  constructor(
    private readonly basePath: string,
    private readonly fetchFn: typeof fetch = (...args) => fetch(...args),
  ) {}

  async list(type: string, query?: ListQuery): Promise<ListResult> {
    const params = this.buildQueryParams(query)
    const qs = params.toString()
    const url = `${this.basePath}/${type}${qs ? '?' + qs : ''}`
    const result = await this.request<SurfaceListResult>(url, { method: 'GET' })
    return {
      data: result.entities.map(this.normalizeEntity),
      meta: {
        total: result.total,
        offset: result.offset,
        limit: result.limit,
      },
    }
  }

  async get(type: string, id: string): Promise<EntityResource> {
    const entity = await this.request<SurfaceEntity>(
      `${this.basePath}/${type}/${id}`,
      { method: 'GET' },
    )
    return this.normalizeEntity(entity)
  }

  async create(type: string, attributes: Record<string, any>): Promise<EntityResource> {
    const entity = await this.request<SurfaceEntity>(
      `${this.basePath}/${type}/action/create`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ attributes }),
      },
    )
    return this.normalizeEntity(entity)
  }

  async update(type: string, id: string, attributes: Record<string, any>): Promise<EntityResource> {
    const entity = await this.request<SurfaceEntity>(
      `${this.basePath}/${type}/action/update`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, attributes }),
      },
    )
    return this.normalizeEntity(entity)
  }

  async remove(type: string, id: string): Promise<void> {
    await this.request(
      `${this.basePath}/${type}/action/delete`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      },
    )
  }

  async schema(type: string): Promise<EntitySchema> {
    return this.request<EntitySchema>(
      `${this.basePath}/${type}/action/schema`,
      { method: 'POST' },
    )
  }

  async search(type: string, field: string, query: string, limit: number = 10): Promise<EntityResource[]> {
    if (query.length < 2) return []
    const result = await this.list(type, {
      filter: { [field]: { operator: 'STARTS_WITH', value: query } },
      sort: field,
      page: { offset: 0, limit },
    })
    return result.data
  }

  async runAction(type: string, action: string, payload?: Record<string, unknown>): Promise<unknown> {
    return this.request(
      `${this.basePath}/${type}/action/${action}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload ? JSON.stringify(payload) : undefined,
      },
    )
  }

  private buildQueryParams(query?: ListQuery): URLSearchParams {
    const params = new URLSearchParams()
    if (!query) return params
    if (query.page) {
      params.set('page[offset]', String(query.page.offset))
      params.set('page[limit]', String(query.page.limit))
    }
    if (query.sort) {
      params.set('sort', query.sort)
    }
    if (query.filter) {
      for (const [field, cond] of Object.entries(query.filter)) {
        params.set(`filter[${field}][operator]`, cond.operator)
        params.set(`filter[${field}][value]`, cond.value)
      }
    }
    return params
  }

  private async request<T>(url: string, init: RequestInit): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...(init.headers as Record<string, string> ?? {}),
    }
    const response = await this.fetchFn(url, { ...init, headers, credentials: 'include' })
    if (response.status === 204) return undefined as unknown as T
    const json = await response.json() as SurfaceResult<T>
    if (!response.ok || !json.ok) {
      const error = json.error
      throw new TransportError(
        error?.status ?? response.status,
        error?.title ?? `HTTP ${response.status}`,
        error?.detail,
        error?.source,
      )
    }
    return json.data as T
  }

  private normalizeEntity(entity: SurfaceEntity): EntityResource {
    return {
      type: entity.type,
      id: entity.id,
      attributes: entity.attributes as Record<string, any>,
    }
  }
}

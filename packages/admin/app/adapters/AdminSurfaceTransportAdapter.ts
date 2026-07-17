import type { TransportAdapter, ListQuery, ListResult, EntityResource, SchemaScope } from '../contracts/transport'
import { TransportError } from '../contracts/transport'
import type { EntitySchema } from '../contracts/schema'
import type {
  AdminSurfaceEntity as SurfaceEntity,
  AdminSurfaceListResult as SurfaceListResult,
  AdminSurfaceResult as SurfaceResult,
} from '../contracts/adminSurface'
import {
  adminSurfaceFetchUrl,
  type AdminSurfaceRouteName,
  type AdminSurfaceRouteParams,
} from '../runtime/adminSurfaceRoutes'

/**
 * Transport adapter for admin surface JSON endpoints.
 *
 * URLs are built with {@link adminSurfaceFetchUrl} so paths stay aligned with
 * `admin_surface.*` routes and PHP `AdminSurfaceRoutePaths`.
 */
export class AdminSurfaceTransportAdapter implements TransportAdapter {
  /**
   * In-flight GET requests keyed by `type:id`, so concurrent reads of the same
   * record share a single network request. Entries are cleared on settle (no
   * persistent cache), so a read issued after the previous one resolves — e.g.
   * a refetch after a save — still hits the server.
   */
  private readonly inflightGets = new Map<string, Promise<EntityResource>>()

  constructor(
    /** Same normalization as the admin plugin (`normalizeAppBaseURL(app.baseURL)`). */
    private readonly normalizedAppBase: string,
    private readonly fetchFn: typeof fetch = (...args) => fetch(...args),
  ) {}

  private surfaceUrl(
    name: AdminSurfaceRouteName,
    params: AdminSurfaceRouteParams,
    queryString: string = '',
  ): string {
    const base = adminSurfaceFetchUrl(this.normalizedAppBase, name, params)
    return queryString !== '' ? `${base}?${queryString}` : base
  }

  async list(type: string, query?: ListQuery): Promise<ListResult> {
    const params = this.buildQueryParams(query)
    const qs = params.toString()
    const url = this.surfaceUrl('admin_surface.list', { type }, qs)
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
    // Collapse concurrent identical reads. The entity detail page mounts a
    // viewer/form and a workflow-history widget in the same tick, all needing
    // the same record — without this they fire duplicate GETs.
    const key = `${type}:${id}`
    const inflight = this.inflightGets.get(key)
    if (inflight !== undefined) return inflight

    const promise = this.request<SurfaceEntity>(
      this.surfaceUrl('admin_surface.get', { type, id }),
      { method: 'GET' },
    )
      .then((entity) => this.normalizeEntity(entity))
      .finally(() => this.inflightGets.delete(key))

    this.inflightGets.set(key, promise)
    return promise
  }

  async create(type: string, attributes: Record<string, any>): Promise<EntityResource> {
    const entity = await this.request<SurfaceEntity>(
      this.surfaceUrl('admin_surface.action', { type, action: 'create' }),
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
      this.surfaceUrl('admin_surface.action', { type, action: 'update' }),
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
      this.surfaceUrl('admin_surface.action', { type, action: 'delete' }),
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      },
    )
  }

  async schema(type: string, scope?: SchemaScope): Promise<EntitySchema> {
    // An entity id scopes an existing record to its bundle. An explicit bundle
    // scopes a create form after the user chooses from the base schema's bundle
    // enum. The host owns validation for both hints.
    const body = JSON.stringify(scope ?? {})
    return this.request<EntitySchema>(
      this.surfaceUrl('admin_surface.action', { type, action: 'schema' }),
      { method: 'POST', headers: { 'Content-Type': 'application/json' }, body },
    )
  }

  async search(
    type: string,
    field: string,
    query: string,
    limit: number = 10,
    operator: 'STARTS_WITH' = 'STARTS_WITH',
    sort: { field: string; direction: 'ASC' } | null = { field, direction: 'ASC' },
  ): Promise<EntityResource[]> {
    if (query.length < 2) return []
    const result = await this.list(type, {
      filter: { [field]: { operator, value: query } },
      ...(sort === null ? {} : { sort: sort.field }),
      page: { offset: 0, limit },
    })
    return result.data
  }

  async runAction(type: string, action: string, payload?: Record<string, unknown>): Promise<unknown> {
    return this.request(
      this.surfaceUrl('admin_surface.action', { type, action }),
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload ?? {}),
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

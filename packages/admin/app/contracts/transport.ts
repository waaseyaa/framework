import type { EntitySchema } from './schema'

export interface TransportAdapter {
  list(type: string, query?: ListQuery): Promise<ListResult>
  get(type: string, id: string): Promise<EntityResource>
  create(type: string, attributes: Record<string, any>): Promise<EntityResource>
  update(type: string, id: string, attributes: Record<string, any>): Promise<EntityResource>
  remove(type: string, id: string): Promise<void>
  schema(type: string, scope?: SchemaScope): Promise<EntitySchema>
  search(
    type: string,
    field: string,
    query: string,
    limit?: number,
    operator?: 'STARTS_WITH' | 'CONTAINS',
    sort?: { field: string; direction: 'ASC' } | null,
  ): Promise<EntityResource[]>
  runAction(type: string, action: string, payload?: Record<string, unknown>): Promise<unknown>
}

export interface SchemaScope {
  id?: string
  bundle?: string
}

export interface ListQuery {
  page?: { offset: number; limit: number }
  sort?: string
  filter?: Record<string, { operator: string; value: string }>
}

export interface ListResult {
  data: EntityResource[]
  meta: { total: number; offset: number; limit: number }
}

export interface EntityResource {
  type: string
  id: string
  attributes: Record<string, any>
  capabilities?: {
    view?: boolean
    edit?: boolean
    delete?: boolean
  }
}

export class TransportError extends Error {
  constructor(
    public readonly status: number,
    public readonly title: string,
    public readonly detail?: string,
    public readonly source?: Record<string, string>,
  ) {
    super(title)
    this.name = 'TransportError'
  }
}

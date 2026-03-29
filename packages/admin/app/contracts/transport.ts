import type { EntitySchema } from './schema'

export interface TransportAdapter {
  list(type: string, query?: ListQuery): Promise<ListResult>
  get(type: string, id: string): Promise<EntityResource>
  create(type: string, attributes: Record<string, any>): Promise<EntityResource>
  update(type: string, id: string, attributes: Record<string, any>): Promise<EntityResource>
  remove(type: string, id: string): Promise<void>
  schema(type: string): Promise<EntitySchema>
  search(type: string, field: string, query: string, limit?: number): Promise<EntityResource[]>
  runAction(type: string, action: string, payload?: Record<string, unknown>): Promise<unknown>
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

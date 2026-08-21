import type { ListQuery, ListResult, EntityResource } from '../contracts/transport'
import { requireAdminRuntime } from './useAdminRuntime'

export type { EntityResource, ListResult, ListQuery }

// Backward-compatible alias — existing components import JsonApiResource from useEntity.
// This re-export prevents breakage during migration. Remove in a future major version.
export type { EntityResource as JsonApiResource }

export function useEntity() {
  const transport = requireAdminRuntime().transport

  async function list(type: string, query?: ListQuery): Promise<ListResult> {
    return transport.list(type, query)
  }

  async function get(type: string, id: string): Promise<EntityResource> {
    return transport.get(type, id)
  }

  async function create(
    type: string,
    attributes: Record<string, any>,
    saveAdvisoryAcknowledgements: string[] = [],
  ): Promise<EntityResource> {
    return transport.create(type, attributes, saveAdvisoryAcknowledgements)
  }

  async function update(
    type: string,
    id: string,
    attributes: Record<string, any>,
    saveAdvisoryAcknowledgements: string[] = [],
  ): Promise<EntityResource> {
    return transport.update(type, id, attributes, saveAdvisoryAcknowledgements)
  }

  async function remove(type: string, id: string): Promise<void> {
    return transport.remove(type, id)
  }

  async function search(
    type: string,
    field: string,
    query: string,
    limit: number = 10,
    operator: 'STARTS_WITH' | 'CONTAINS' = 'STARTS_WITH',
    sort: { field: string; direction: 'ASC' } | null = { field, direction: 'ASC' },
  ): Promise<EntityResource[]> {
    return transport.search(type, field, query, limit, operator, sort)
  }

  async function runAction(type: string, action: string, payload?: Record<string, unknown>): Promise<unknown> {
    return transport.runAction(type, action, payload)
  }

  return { list, get, create, update, remove, search, runAction }
}

import type { AdminRuntime } from '../contracts/runtime'
import type { ListQuery, ListResult, EntityResource } from '../contracts/transport'

export type { EntityResource, ListResult, ListQuery }

// Backward-compatible alias — existing components import JsonApiResource from useEntity.
// This re-export prevents breakage during migration. Remove in a future major version.
export type { EntityResource as JsonApiResource }

export function useEntity() {
  const { $admin } = useNuxtApp() as unknown as { $admin: AdminRuntime }
  const transport = $admin.transport

  async function list(type: string, query?: ListQuery): Promise<ListResult> {
    return transport.list(type, query)
  }

  async function get(type: string, id: string): Promise<EntityResource> {
    return transport.get(type, id)
  }

  async function create(type: string, attributes: Record<string, any>): Promise<EntityResource> {
    return transport.create(type, attributes)
  }

  async function update(type: string, id: string, attributes: Record<string, any>): Promise<EntityResource> {
    return transport.update(type, id, attributes)
  }

  async function remove(type: string, id: string): Promise<void> {
    return transport.remove(type, id)
  }

  async function search(type: string, labelField: string, query: string, limit: number = 10): Promise<EntityResource[]> {
    return transport.search(type, labelField, query, limit)
  }

  return { list, get, create, update, remove, search }
}

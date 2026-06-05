import { ref, type Ref } from 'vue'
import type { SchemaProperty, EntitySchema } from '../contracts/schema'
import { requireAdminRuntime } from './useAdminRuntime'

export type { SchemaProperty, EntitySchema }

const schemaCache = new Map<string, EntitySchema>()
const inflightCache = new Map<string, Promise<EntitySchema>>()

export function useSchema(entityType: string) {
  const schema: Ref<EntitySchema | null> = ref(null)
  const loading = ref(false)
  const error: Ref<string | null> = ref(null)

  // Schemas are cached per entity type AND per scoping entity id, because a
  // bundled content type (e.g. a node of bundle "page") has a different field
  // set than the bare entity type. Passing the entity id lets the backend scope
  // the schema to that entity's bundle so its per-bundle fields (body, blocks)
  // appear in the form. No id (list/create) keeps the base, core-field schema.
  function cacheKey(scopeId?: string) {
    return `${entityType}:${scopeId ?? ''}`
  }

  async function fetch(scopeId?: string) {
    const key = cacheKey(scopeId)

    if (schemaCache.has(key)) {
      schema.value = schemaCache.get(key)!
      return
    }

    // FR-001: return in-flight Promise if one exists for this key
    const inflight = inflightCache.get(key)
    if (inflight !== undefined) {
      schema.value = await inflight
      return
    }

    loading.value = true
    error.value = null

    try {
      // FR-001: register the in-flight Promise before awaiting.
      // requireAdminRuntime() call is inside try so a synchronous throw
      // (e.g. runtime unavailable) is caught and sets error.value.
      const promise = requireAdminRuntime()
        .transport.schema(entityType, scopeId)
        .then((result: EntitySchema) => {
          schemaCache.set(key, result)
          inflightCache.delete(key) // clean up after resolution
          return result
        })
        .catch((e: unknown) => {
          inflightCache.delete(key) // FR-002: clear on rejection, no poison-caching
          throw e
        })

      inflightCache.set(key, promise)
      schema.value = await promise
    } catch (e: any) {
      error.value = e.detail ?? e.message ?? 'Failed to load schema'
    } finally {
      loading.value = false
    }
  }

  function invalidate(scopeId?: string) {
    const key = cacheKey(scopeId)
    schemaCache.delete(key)
    inflightCache.delete(key) // FR-003: clear in-flight on invalidate
  }

  /**
   * Return properties sorted by x-weight.
   *
   * When `editable` is true:
   *  - System readOnly fields (id, uuid — no x-access-restricted) are excluded.
   *  - Hidden widgets are excluded.
   *  - Access-restricted fields (readOnly + x-access-restricted) are kept — they
   *    render as disabled widgets so users can see but not edit the value.
   *
   * When false (default), all properties are returned.
   */
  function sortedProperties(editable = false) {
    if (!schema.value) return []

    const entries = Object.entries(schema.value.properties)

    const filtered = editable
      ? entries.filter(([, prop]) => {
          if (prop['x-widget'] === 'hidden') return false
          // System readOnly (no x-access-restricted) → exclude from form.
          if (prop.readOnly && !prop['x-access-restricted']) return false
          return true
        })
      : entries

    return filtered.sort(([, a], [, b]) => {
      const wa = a['x-weight'] ?? 0
      const wb = b['x-weight'] ?? 0
      return wa - wb
    })
  }

  return { schema, loading, error, fetch, invalidate, sortedProperties }
}

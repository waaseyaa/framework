import { ref, type Ref } from 'vue'

export interface SchemaProperty {
  type: string
  description?: string
  format?: string
  readOnly?: boolean
  enum?: string[]
  minimum?: number
  maximum?: number
  maxLength?: number
  'x-widget'?: string
  'x-label'?: string
  'x-description'?: string
  'x-weight'?: number
  'x-required'?: boolean
  'x-enum-labels'?: Record<string, string>
  'x-target-type'?: string
  'x-access-restricted'?: boolean
  default?: string | number | boolean
}

export interface EntitySchema {
  $schema: string
  title: string
  description: string
  type: string
  'x-entity-type': string
  'x-translatable': boolean
  'x-revisionable': boolean
  properties: Record<string, SchemaProperty>
  required?: string[]
}

const schemaCache = new Map<string, EntitySchema>()

export function useSchema(entityType: string) {
  const schema: Ref<EntitySchema | null> = ref(null)
  const loading = ref(false)
  const error: Ref<string | null> = ref(null)

  async function fetch() {
    if (schemaCache.has(entityType)) {
      schema.value = schemaCache.get(entityType)!
      return
    }

    loading.value = true
    error.value = null

    try {
      const response = await $fetch<{ meta: { schema: EntitySchema } }>(
        `/api/schema/${entityType}`,
      )
      schema.value = response.meta.schema
      schemaCache.set(entityType, schema.value)
    } catch (e: any) {
      error.value = e.data?.errors?.[0]?.detail ?? e.message ?? 'Failed to load schema'
    } finally {
      loading.value = false
    }
  }

  function invalidate() {
    schemaCache.delete(entityType)
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

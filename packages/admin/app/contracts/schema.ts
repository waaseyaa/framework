export interface SchemaProperty {
  type: string
  description?: string
  format?: string
  readOnly?: boolean
  default?: any
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
  'x-source-field'?: string
  'x-list-display'?: boolean
  /** 1 for scalar fields; -1 or a value greater than 1 for multi-value fields. */
  'x-cardinality'?: number
}

export interface EntitySchema {
  $schema: string
  title: string
  description: string
  type: string
  'x-entity-type': string
  'x-translatable': boolean
  'x-revisionable': boolean
  /**
   * The property name that holds the bundle value (e.g. 'type' for nodes).
   * Null when the entity type has no bundle key. Drives the bundle filter in
   * `SchemaList`. Added in M3A (#1413).
   */
  'x-bundle-key'?: string | null
  'x-workflow'?: {
    bound: boolean
    id: string | null
  }
  properties: Record<string, SchemaProperty>
  required?: string[]
}

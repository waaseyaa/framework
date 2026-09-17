/** JSON Schema subset emitted by the Admin Surface schema action. */
export interface AdminSurfaceSchemaProperty {
  type: string
  description?: string
  format?: string
  readOnly?: boolean
  default?: any
  enum?: Array<string | number>
  items?: AdminSurfaceSchemaProperty
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
  'x-target-filter'?: Record<string, string>
  'x-access-restricted'?: boolean
  'x-source-field'?: string
  'x-list-display'?: boolean
  'x-cardinality'?: number
  'x-min'?: string
  'x-max'?: string
}

export interface AdminSurfaceFormSection {
  id: string
  label: string
  description?: string
  fields: string[]
  collapsible?: boolean
  collapsed?: boolean
}

export interface AdminSurfaceSchemaRequest {
  id?: string
  bundle?: string
}

export interface AdminSurfaceEntitySchema {
  $schema: string
  title: string
  description: string
  type: string
  'x-entity-type': string
  'x-translatable': boolean
  'x-revisionable': boolean
  'x-bundle-key'?: string | null
  'x-workflow'?: { bound: boolean; id: string | null }
  'x-preview'?: { action: string }
  'x-list'?: unknown
  'x-form-sections'?: AdminSurfaceFormSection[]
  properties: Record<string, AdminSurfaceSchemaProperty>
  /** The canonical PHP presenter emits false; optional preserves legacy schema fixtures. */
  additionalProperties?: false
  required?: string[]
}

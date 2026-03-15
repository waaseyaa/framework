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

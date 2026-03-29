export interface CatalogEntry {
  id: string
  label: string
  description?: string
  keys?: Record<string, string>
  group?: string
  disabled?: boolean
  capabilities: CatalogCapabilities
}

export interface CatalogCapabilities {
  list: boolean
  get: boolean
  create: boolean
  update: boolean
  delete: boolean
  schema: boolean
}

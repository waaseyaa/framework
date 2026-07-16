import type {
  AdminSurfaceAction,
  AdminSurfaceCapabilities as CatalogCapabilities,
  AdminSurfaceField,
} from './adminSurface'

export interface AdminRuntimeCatalogEntry {
  id: string
  label: string
  group?: string
  description?: string
  disabled?: boolean
  fields: AdminSurfaceField[]
  actions: AdminSurfaceAction[]
  capabilities: CatalogCapabilities
  reference?: {
    labelField: string
    search: { field: string; operator: 'STARTS_WITH' } | null
    sort: { field: string; direction: 'ASC' } | null
  }
}

export type CatalogEntry = AdminRuntimeCatalogEntry
export type { CatalogCapabilities }

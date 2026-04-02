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
}

export type CatalogEntry = AdminRuntimeCatalogEntry
export type { CatalogCapabilities }

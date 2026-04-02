import type {
  AdminSurfaceAction,
  AdminSurfaceCatalogEntry,
  AdminSurfaceCapabilities as CatalogCapabilities,
  AdminSurfaceField,
} from '../../../admin-surface/contract/types'

export interface CatalogEntry {
  id: string
  label: string
  group?: string
  description?: string
  disabled?: boolean
  keys?: { id: string; label: string }
  fields: AdminSurfaceField[]
  actions: AdminSurfaceAction[]
  capabilities: CatalogCapabilities
}

export type { AdminSurfaceCatalogEntry, CatalogCapabilities }

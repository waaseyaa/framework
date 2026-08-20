export interface AdminSurfaceSession {
  account: AdminSurfaceAccount
  tenant: AdminSurfaceTenant
  policies: string[]
  features?: Record<string, boolean>
  /**
   * Server-authoritative per-principal permission projection (host-allowlisted;
   * see packages/admin-surface/contract/types.ts). Consume via useAdmin().can().
   */
  capabilities?: Record<string, boolean>
}

export interface AdminSurfaceAccount {
  id: string
  name: string
  email?: string
  roles: string[]
}

export interface AdminSurfaceTenant {
  id: string
  name: string
}

export interface AdminSurfaceCatalog {
  entities: AdminSurfaceCatalogEntry[]
}

export interface AdminSurfaceCatalogEntry {
  id: string
  label: string
  description?: string
  group?: string
  disabled?: boolean
  fields: AdminSurfaceField[]
  actions: AdminSurfaceAction[]
  capabilities: AdminSurfaceCapabilities
  reference?: {
    labelField: string
    search: { field: string; operator: 'STARTS_WITH' | 'CONTAINS' } | null
    sort: { field: string; direction: 'ASC' } | null
  }
}

export interface AdminSurfaceCapabilities {
  list: boolean
  get: boolean
  create: boolean
  update: boolean
  delete: boolean
  schema: boolean
}

export interface AdminSurfaceField {
  name: string
  label: string
  type: string
  widget?: string
  weight?: number
  required?: boolean
  readOnly?: boolean
  accessRestricted?: boolean
  options?: Record<string, unknown>
}

export interface AdminSurfaceAction {
  id: string
  label: string
  scope: 'entity' | 'collection'
  confirmation?: string
  dangerous?: boolean
}

export interface AdminSurfaceEntity {
  type: string
  id: string
  attributes: Record<string, unknown>
  mutation_token?: string
  capabilities?: {
    view?: boolean
    edit?: boolean
    delete?: boolean
  }
}

export interface AdminSurfaceResult<T> {
  ok: boolean
  data?: T
  error?: AdminSurfaceError
  meta?: Record<string, unknown>
}

export interface AdminSurfaceError {
  status: number
  title: string
  detail?: string
  source?: Record<string, string>
  code?: string
  meta?: Record<string, unknown>
}

export interface AdminSurfaceListQuery {
  page?: { offset: number; limit: number }
  sort?: string
  filter?: Record<string, { operator: string; value: string }>
}

export interface AdminSurfaceListResult {
  entities: AdminSurfaceEntity[]
  total: number
  offset: number
  limit: number
}

/**
 * Shared types for the Admin Surface contract.
 *
 * These types define the integration boundary between the admin SPA
 * and any host application built on Waaseyaa.
 */

// ── Session ──────────────────────────────────────────────────────

export interface AdminSurfaceSession {
  account: AdminSurfaceAccount
  tenant: AdminSurfaceTenant
  policies: string[]
  features?: Record<string, boolean>
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

// ── Catalog ──────────────────────────────────────────────────────

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
}

export interface AdminSurfaceCapabilities {
  list: boolean
  get: boolean
  create: boolean
  update: boolean
  delete: boolean
  schema: boolean
}

// ── Fields ───────────────────────────────────────────────────────

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

// ── Actions ──────────────────────────────────────────────────────

export interface AdminSurfaceAction {
  id: string
  label: string
  scope: 'entity' | 'collection'
  confirmation?: string
  dangerous?: boolean
}

// ── Entity ───────────────────────────────────────────────────────

export interface AdminSurfaceEntity {
  type: string
  id: string
  attributes: Record<string, unknown>
}

// ── Result ───────────────────────────────────────────────────────

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
}

// ── List ─────────────────────────────────────────────────────────

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

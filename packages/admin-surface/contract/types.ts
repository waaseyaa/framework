/**
 * Shared types for the Admin Surface contract.
 *
 * These types define the integration boundary between the admin SPA
 * and any host application built on Waaseyaa.
 */

// ── Session ──────────────────────────────────────────────────────

export interface AdminSurfaceHeaderLink {
  label: string
  href: string
  external?: boolean
}

export interface AdminSurfaceSidebarItem {
  id: string
  label: string
  href: string
  group?: string
  weight?: number
}

/** Optional chrome injected by the PHP host (see GenericAdminSurfaceHost::buildAdminUi). */
export interface AdminSurfaceUiCustomization {
  headerLinks?: AdminSurfaceHeaderLink[]
  sidebarItems?: AdminSurfaceSidebarItem[]
  navigationMode?: 'full' | 'catalog-only'
}

export interface AdminSurfaceSession {
  account: AdminSurfaceAccount
  tenant: AdminSurfaceTenant
  policies: string[]
  features?: Record<string, boolean>
  /**
   * Server-authoritative per-principal permission projection.
   *
   * Keys are the permission identifiers the PHP host explicitly allowlisted
   * (`GenericAdminSurfaceHost` `$capabilityAllowlist`, bounded and
   * deduplicated); values come from `hasPermission()` on the resolved
   * principal. Unlike `features` (installation-wide) this varies per account.
   * An empty object when the host configures no allowlist. (Keep braces out
   * of this comment — the PHP conformance parser ends the interface body at
   * the first closing brace.) Consume via `useAdmin().can()`
   * — which honors only an exact boolean `true` — and never derive access
   * from `account.roles`. Server middleware remains the enforcement boundary;
   * this is a UI affordance signal only.
   */
  capabilities?: Record<string, boolean>
  ui?: AdminSurfaceUiCustomization
}

export interface AdminSurfaceAccount {
  id: string
  name: string
  email?: string
  /**
   * Email-verification state.
   *
   * Emitted by the PHP host (`AdminSurfaceSessionData::toArray()` writes
   * `account.emailVerified`) and consumed by the SPA runtime
   * (`auth.global` middleware and `VerificationBanner.vue`).
   *
   * Optional: hosts that do not implement email verification may omit it,
   * in which case the SPA treats the account as unverified for gating
   * purposes (see `runtimeConfig.public.requireVerifiedEmail`).
   */
  emailVerified?: boolean
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
  reference?: AdminSurfaceReferenceMetadata
  fields: AdminSurfaceField[]
  actions: AdminSurfaceAction[]
  capabilities: AdminSurfaceCapabilities
}

export interface AdminSurfaceReferenceMetadata {
  labelField: string
  search: { field: string; operator: 'STARTS_WITH' | 'CONTAINS' } | null
  sort: { field: string; direction: 'ASC' } | null
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
  capabilities?: {
    view?: boolean
    edit?: boolean
    delete?: boolean
  }
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

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
  features: Record<string, boolean>
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
  capabilities: Record<string, boolean>
  ui?: AdminSurfaceUiCustomization
}

export interface AdminSurfaceAccount {
  id: string
  name: string
  email: string | null
  /**
   * Email-verification state.
   *
   * Emitted by the PHP host (`AdminSurfaceSessionData::toArray()` writes
   * `account.emailVerified`) and consumed by the SPA runtime
   * (`auth.global` middleware and `VerificationBanner.vue`).
   *
   * Null when the host does not provide an email-verification decision. The
   * SPA treats null as unverified when verification gating is enabled.
   */
  emailVerified: boolean | null
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
  /**
   * Whether this type keeps revision history. A type that does not has no
   * history surface at all: the endpoint answers 404 rather than an empty
   * list, so a client must withhold the affordance instead of offering it and
   * then being refused.
   */
  revisions: boolean
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
  /**
   * Opaque validator for a later fenced update, delete, or restore.
   *
   * A non-null value is emitted when the caller can mutate the entity; null is
   * emitted otherwise. The SPA must echo the exact non-null value as
   * `mutation_token`; it must not derive or inspect it.
   */
  mutation_token: string | null
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

/**
 * Candidate-bound save advisory projected onto the Admin error envelope.
 *
 * Emitted by `AdminSurfaceResultData::fromJsonApiError()` from JSON:API
 * `error.meta.save_advisories` and consumed by SchemaForm. Extra JSON:API
 * meta keys never cross this boundary.
 */
export interface AdminSurfaceSaveAdvisory {
  code: string
  field: string
  severity: 'warning'
  message: string
  acknowledgement: string
}

export interface AdminSurfaceErrorMeta {
  save_advisories: AdminSurfaceSaveAdvisory[]
}

export interface AdminSurfaceError {
  status: number
  title: string
  detail?: string
  source?: Record<string, string>
  code?: string
  meta?: AdminSurfaceErrorMeta
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

// ── Core mutation requests ───────────────────────────────────────

export interface AdminSurfaceCreateRequest {
  attributes: Record<string, unknown>
  save_advisory_acknowledgements?: string[]
}

export interface AdminSurfaceUpdateRequest extends AdminSurfaceCreateRequest {
  id: string
  mutation_token: string
}

export interface AdminSurfaceDeleteRequest {
  id: string
  mutation_token: string
}

export interface AdminSurfaceDeleteData {
  deleted: true
}

export interface AdminSurfaceGenerateSlugRequest {
  value: string
}

export interface AdminSurfaceGenerateSlugData {
  slug: string
}

export type {
  AdminSurfaceEntitySchema,
  AdminSurfaceFormSection,
  AdminSurfaceSchemaProperty,
  AdminSurfaceSchemaRequest,
} from './schema'

export type {
  AdminSurfaceHistoryData,
  AdminSurfaceHistoryRequest,
  AdminSurfaceRestoreRevisionData,
  AdminSurfaceRestoreRevisionRequest,
  AdminSurfaceRevisionData,
  AdminSurfaceRevisionEntity,
  AdminSurfaceRevisionEntry,
  AdminSurfaceRevisionPreviewData,
  AdminSurfaceRevisionRequest,
} from './revisions'

export type * from './pageBuilder'

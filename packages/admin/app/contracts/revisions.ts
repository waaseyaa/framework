import type { AdminSurfaceEntity } from './adminSurface'

export interface AdminSurfaceHistoryRequest {
  id: string
}

export interface AdminSurfaceRevisionRequest extends AdminSurfaceHistoryRequest {
  revision_id: number
}

export interface AdminSurfaceRestoreRevisionRequest extends AdminSurfaceRevisionRequest {
  expected_latest_revision_id: number
  mutation_token: string
}

export interface AdminSurfaceRevisionEntry {
  revisionId: number | string | null
  createdAt: string | null
  author: number | null
  log: string | null
  isCurrent: boolean
  isLatest: boolean
}

export interface AdminSurfaceHistoryData {
  entityType: string
  entityId: string
  revisions: AdminSurfaceRevisionEntry[]
}

export interface AdminSurfaceRevisionEntity {
  type: string
  id: string
  attributes: Record<string, unknown>
}

export interface AdminSurfaceRevisionData {
  entityType: string
  entityId: string
  revisionId: number
  entity: AdminSurfaceRevisionEntity
}

export interface AdminSurfaceRevisionPreviewData {
  revisionId: number
  previewUrl: string
}

export interface AdminSurfaceRestoreRevisionData {
  entityType: string
  entityId: string
  sourceRevisionId: number
  resultingRevisionId: number | null
  entity: AdminSurfaceEntity
}

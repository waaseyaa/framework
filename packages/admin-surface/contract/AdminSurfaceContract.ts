import type {
  AdminSurfaceSession,
  AdminSurfaceCatalog,
  AdminSurfaceEntity,
  AdminSurfaceResult,
  AdminSurfaceListQuery,
  AdminSurfaceListResult,
} from './types'

export const ADMIN_SURFACE_VERSION = '0.1' as const

/**
 * The canonical contract between the admin SPA and any Waaseyaa host application.
 *
 * Host applications implement the backend (PHP) side via AbstractAdminSurfaceHost.
 * The admin SPA consumes this contract through its transport adapter layer.
 */
export interface AdminSurfaceContract {
  /**
   * Returns identity, roles, and policies for the current session.
   */
  getSession(): Promise<AdminSurfaceResult<AdminSurfaceSession>>

  /**
   * Returns the entity catalog — types, fields, actions, and capabilities.
   */
  getCatalog(): Promise<AdminSurfaceResult<AdminSurfaceCatalog>>

  /**
   * Lists entities of a given type with optional filtering, sorting, and pagination.
   */
  listEntities(
    type: string,
    query?: AdminSurfaceListQuery,
  ): Promise<AdminSurfaceResult<AdminSurfaceListResult>>

  /**
   * Retrieves a single entity by type and ID.
   */
  getEntity(
    type: string,
    id: string,
  ): Promise<AdminSurfaceResult<AdminSurfaceEntity>>

  /**
   * Executes a named action on an entity type with an optional payload.
   */
  runAction(
    type: string,
    action: string,
    payload?: Record<string, unknown>,
  ): Promise<AdminSurfaceResult<unknown>>
}

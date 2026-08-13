import { joinURL } from 'ufo'

/**
 * Named admin surface routes (mirrors PHP route names on Waaseyaa\AdminSurface\AdminSurfaceServiceProvider).
 */
export type AdminSurfaceRouteName =
  | 'admin_surface.session'
  | 'admin_surface.catalog'
  | 'admin_surface.list'
  | 'admin_surface.get'
  | 'admin_surface.action'
  | 'admin_surface.page_builder.definitions'
  | 'admin_surface.page_builder.draft'
  | 'admin_surface.page_builder.command'
  | 'admin_surface.page_builder.preview'

export type AdminSurfaceRouteParams = {
  type?: string
  id?: string
  action?: string
  surface?: string
}

function requireParam(key: keyof AdminSurfaceRouteParams, value: string | undefined): string {
  if (value === undefined || value === '') {
    throw new Error(`Missing required parameter "${key}" for admin surface route.`)
  }
  return value
}

/**
 * Path after the Nuxt app baseURL (e.g. "/admin/") for `$fetch`.
 * Keep aligned with `Waaseyaa\AdminSurface\AdminSurfaceRoutePaths` (PHP).
 */
export function adminSurfacePathRelativeToAppBase(
  name: AdminSurfaceRouteName,
  params: AdminSurfaceRouteParams = {},
): string {
  switch (name) {
    case 'admin_surface.session':
      return '_surface/session'
    case 'admin_surface.catalog':
      return '_surface/catalog'
    case 'admin_surface.list': {
      const type = requireParam('type', params.type)
      return `_surface/${encodeURIComponent(type)}`
    }
    case 'admin_surface.get': {
      const type = requireParam('type', params.type)
      const id = requireParam('id', params.id)
      return `_surface/${encodeURIComponent(type)}/${encodeURIComponent(id)}`
    }
    case 'admin_surface.action': {
      const type = requireParam('type', params.type)
      const action = requireParam('action', params.action)
      return `_surface/${encodeURIComponent(type)}/action/${encodeURIComponent(action)}`
    }
    case 'admin_surface.page_builder.definitions': {
      const surface = requireParam('surface', params.surface)
      return `_surface/page-builder/${encodeURIComponent(surface)}/definitions`
    }
    case 'admin_surface.page_builder.draft': {
      const surface = requireParam('surface', params.surface)
      const id = requireParam('id', params.id)
      return `_surface/page-builder/${encodeURIComponent(surface)}/${encodeURIComponent(id)}`
    }
    case 'admin_surface.page_builder.command': {
      const surface = requireParam('surface', params.surface)
      const id = requireParam('id', params.id)
      return `_surface/page-builder/${encodeURIComponent(surface)}/${encodeURIComponent(id)}/commands`
    }
    case 'admin_surface.page_builder.preview': {
      const surface = requireParam('surface', params.surface)
      const id = requireParam('id', params.id)
      return `_surface/page-builder/${encodeURIComponent(surface)}/${encodeURIComponent(id)}/preview`
    }
  }
}

/**
 * Full fetch URL for the admin surface from normalized Nuxt `app.baseURL`.
 */
export function adminSurfaceFetchUrl(
  normalizedAppBase: string,
  name: AdminSurfaceRouteName,
  params?: AdminSurfaceRouteParams,
): string {
  return joinURL(normalizedAppBase, adminSurfacePathRelativeToAppBase(name, params))
}

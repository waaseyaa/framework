import { describe, expect, it } from 'vitest'
import {
  adminSurfaceFetchUrl,
  adminSurfacePathRelativeToAppBase,
} from '~/runtime/adminSurfaceRoutes'

describe('adminSurfaceRoutes', () => {
  it('builds session and catalog paths relative to app base', () => {
    expect(adminSurfacePathRelativeToAppBase('admin_surface.session')).toBe('_surface/session')
    expect(adminSurfacePathRelativeToAppBase('admin_surface.catalog')).toBe('_surface/catalog')
  })

  it('joins normalized app base for fetch URLs', () => {
    expect(adminSurfaceFetchUrl('/admin/', 'admin_surface.session')).toBe('/admin/_surface/session')
    expect(adminSurfaceFetchUrl('/admin/', 'admin_surface.catalog')).toBe('/admin/_surface/catalog')
  })

  it('builds parameterized paths', () => {
    expect(adminSurfacePathRelativeToAppBase('admin_surface.list', { type: 'article' })).toBe(
      '_surface/article',
    )
    expect(
      adminSurfacePathRelativeToAppBase('admin_surface.get', { type: 'article', id: '42' }),
    ).toBe('_surface/article/42')
    expect(
      adminSurfacePathRelativeToAppBase('admin_surface.action', { type: 'article', action: 'create' }),
    ).toBe('_surface/article/action/create')
  })

  it('builds the shared page-builder paths for either client shell', () => {
    expect(adminSurfacePathRelativeToAppBase('admin_surface.page_builder.definitions', {
      surface: 'page',
    })).toBe('_surface/page-builder/page/definitions')
    expect(adminSurfacePathRelativeToAppBase('admin_surface.page_builder.draft', {
      surface: 'page',
      id: 'about-us',
    })).toBe('_surface/page-builder/page/about-us')
    expect(adminSurfacePathRelativeToAppBase('admin_surface.page_builder.command', {
      surface: 'landing page',
      id: '42/preview',
    })).toBe('_surface/page-builder/landing%20page/42%2Fpreview/commands')
    expect(adminSurfacePathRelativeToAppBase('admin_surface.page_builder.preview', {
      surface: 'page',
      id: '42',
    })).toBe('_surface/page-builder/page/42/preview')
  })

  it('throws on missing required params', () => {
    expect(() => adminSurfacePathRelativeToAppBase('admin_surface.list', {})).toThrow(/type/)
    expect(() => adminSurfacePathRelativeToAppBase('admin_surface.page_builder.draft', {
      surface: 'page',
    })).toThrow(/id/)
  })
})

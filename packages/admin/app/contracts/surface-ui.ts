/**
 * Local mirror of the admin-surface session `ui` subset from
 * `packages/admin-surface/contract/types.ts`.
 *
 * Kept under `app/contracts/` so `tsc -p tsconfig.contracts.json` (rootDir `app`)
 * does not pull in files outside `packages/admin/app`. Update both sides together.
 */

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

export interface AdminSurfaceUiCustomization {
  headerLinks?: AdminSurfaceHeaderLink[]
  sidebarItems?: AdminSurfaceSidebarItem[]
  navigationMode?: 'full' | 'catalog-only'
}

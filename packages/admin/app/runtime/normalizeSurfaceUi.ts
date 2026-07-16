import type {
  AdminSurfaceHeaderLink,
  AdminSurfaceSidebarItem,
  AdminSurfaceUiCustomization,
} from '../contracts/surface-ui'

function normalizeHeaderLinks(raw: unknown): AdminSurfaceHeaderLink[] {
  if (!Array.isArray(raw)) {
    return []
  }
  const out: AdminSurfaceHeaderLink[] = []
  for (const row of raw) {
    if (row === null || typeof row !== 'object') {
      continue
    }
    const o = row as Record<string, unknown>
    const label = typeof o.label === 'string' ? o.label.trim() : ''
    const href = typeof o.href === 'string' ? o.href.trim() : ''
    if (label === '' || href === '') {
      continue
    }
    const item: AdminSurfaceHeaderLink = { label, href }
    if (typeof o.external === 'boolean') {
      item.external = o.external
    }
    out.push(item)
  }

  return out
}

function normalizeSidebarItems(raw: unknown): AdminSurfaceSidebarItem[] {
  if (!Array.isArray(raw)) {
    return []
  }
  const out: AdminSurfaceSidebarItem[] = []
  for (const row of raw) {
    if (row === null || typeof row !== 'object') {
      continue
    }
    const o = row as Record<string, unknown>
    const id = typeof o.id === 'string' ? o.id.trim() : ''
    const label = typeof o.label === 'string' ? o.label.trim() : ''
    const href = typeof o.href === 'string' ? o.href.trim() : ''
    if (id === '' || label === '' || href === '') {
      continue
    }
    const item: AdminSurfaceSidebarItem = { id, label, href }
    if (typeof o.group === 'string' && o.group !== '') {
      item.group = o.group
    }
    if (typeof o.weight === 'number' && Number.isFinite(o.weight)) {
      item.weight = o.weight
    }
    out.push(item)
  }

  return out
}

/** Maps optional session `ui` into stable runtime arrays (defensive against malformed JSON). */
export function normalizeSurfaceUi(ui: AdminSurfaceUiCustomization | undefined): {
  headerLinks: AdminSurfaceHeaderLink[]
  sidebarItems: AdminSurfaceSidebarItem[]
  navigationMode: 'full' | 'catalog-only'
} {
  return {
    headerLinks: normalizeHeaderLinks(ui?.headerLinks),
    sidebarItems: normalizeSidebarItems(ui?.sidebarItems),
    navigationMode: ui?.navigationMode === 'catalog-only' ? 'catalog-only' : 'full',
  }
}

import { SessionAuthAdapter } from '../adapters/SessionAuthAdapter'
import { AdminSurfaceTransportAdapter } from '../adapters/AdminSurfaceTransportAdapter'
import type { AdminRuntime, AdminAuthConfig } from '../contracts/runtime'
import type { CatalogEntry } from '../contracts/catalog'

interface SurfaceSession {
  account: { id: string; name: string; email?: string; roles: string[] }
  tenant: { id: string; name: string }
  policies: string[]
  features?: Record<string, boolean>
}

interface SurfaceCatalogEntry {
  id: string
  label: string
  description?: string
  group?: string
  disabled?: boolean
  fields: unknown[]
  actions: unknown[]
  capabilities: { list: boolean; get: boolean; create: boolean; update: boolean; delete: boolean; schema: boolean }
}

interface SurfaceResult<T> {
  ok: boolean
  data?: T
  error?: { status: number; title: string; detail?: string }
}

export default defineNuxtPlugin(async (): Promise<{ provide: { admin: AdminRuntime | null } }> => {
  const config = useRuntimeConfig()
  const baseUrl = (config.public.baseUrl as string) || ''
  const surfacePath = `${baseUrl}/_surface`

  // ── Skip auth check on public auth pages (prevents redirect loop) ─────
  const publicAuthPaths = ['/login', '/register', '/forgot-password', '/reset-password', '/verify-email']
  if (import.meta.client) {
    const path = window.location.pathname.replace(/\/+$/, '')
    if (publicAuthPaths.some(p => path.endsWith(p))) {
      return { provide: { admin: null } }
    }
  }
  if (import.meta.server) {
    const route = useRoute()
    if (publicAuthPaths.includes(route.path)) {
      return { provide: { admin: null } }
    }
  }

  // ── Fetch session from AdminSurface API ──────────────────────────

  let surfaceSession: SurfaceSession | null = null
  let surfaceCatalog: SurfaceCatalogEntry[] | null = null

  try {
    const sessionRes = await $fetch<SurfaceResult<SurfaceSession>>(`${surfacePath}/session`, {
      baseURL: '/',
      ignoreResponseError: true,
      credentials: 'include',
    })

    if (sessionRes && sessionRes.ok && sessionRes.data) {
      surfaceSession = sessionRes.data

      const catalogRes = await $fetch<SurfaceResult<{ entities: SurfaceCatalogEntry[] }>>(`${surfacePath}/catalog`, {
        baseURL: '/',
        ignoreResponseError: true,
        credentials: 'include',
      })
      if (catalogRes && catalogRes.ok && catalogRes.data) {
        surfaceCatalog = catalogRes.data.entities
      }
    } else if (sessionRes && !sessionRes.ok && sessionRes.error?.status === 401) {
      await navigateTo('/login', { replace: true })
      return { provide: { admin: null } }
    }
  } catch {
    // Surface API not available
    console.error('[waaseyaa:admin] AdminSurface API not available')
    throw createError({
      statusCode: 503,
      message: 'Unable to reach the admin API. Ensure the PHP backend is running with an AdminSurfaceHost registered.',
      fatal: true,
    })
  }

  if (!surfaceSession || !surfaceCatalog) {
    await navigateTo('/login', { replace: true })
    return { provide: { admin: null } }
  }

  // ── Build runtime from surface response ──────────────────────────

  const catalog: CatalogEntry[] = surfaceCatalog.map(entry => ({
    id: entry.id,
    label: entry.label,
    description: entry.description,
    group: entry.group,
    disabled: entry.disabled,
    capabilities: entry.capabilities,
  }))

  const account = surfaceSession.account
  const tenant = { ...surfaceSession.tenant, scopingStrategy: 'server' as const }
  const authConfig: AdminAuthConfig = { strategy: 'redirect', loginUrl: '/login' }

  const auth = new SessionAuthAdapter(account, tenant, authConfig, surfaceSession.features)
  const transport = new AdminSurfaceTransportAdapter(surfacePath)

  const runtime: AdminRuntime = {
    auth,
    authConfig,
    transport,
    catalog,
    tenant,
    account,
    features: surfaceSession.features,
  }

  return { provide: { admin: runtime } }
})

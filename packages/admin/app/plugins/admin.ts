import { BootstrapAuthAdapter } from '../adapters/BootstrapAuthAdapter'
import { JsonApiTransportAdapter } from '../adapters/JsonApiTransportAdapter'
import { AdminSurfaceTransportAdapter } from '../adapters/AdminSurfaceTransportAdapter'
import { ADMIN_CONTRACT_VERSION } from '../contracts/version'
import type { AdminBootstrap } from '../contracts/bootstrap'
import type { AdminRuntime } from '../contracts/runtime'
import type { AdminSession } from '../contracts/auth'
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
  const surfacePath = `${baseUrl}/admin/surface`

  // ── Try surface endpoints first (version negotiation) ──────────

  let useSurface = false
  let surfaceSession: SurfaceSession | null = null
  let surfaceCatalog: SurfaceCatalogEntry[] | null = null

  try {
    const sessionRes = await $fetch<SurfaceResult<SurfaceSession>>(`${surfacePath}/session`, {
      ignoreResponseError: true,
      credentials: 'include',
    })
    if (sessionRes && sessionRes.ok && sessionRes.data) {
      surfaceSession = sessionRes.data

      const catalogRes = await $fetch<SurfaceResult<{ entities: SurfaceCatalogEntry[] }>>(`${surfacePath}/catalog`, {
        ignoreResponseError: true,
        credentials: 'include',
      })
      if (catalogRes && catalogRes.ok && catalogRes.data) {
        surfaceCatalog = catalogRes.data.entities
        useSurface = true
      }
    } else if (sessionRes && !sessionRes.ok && sessionRes.error?.status === 401) {
      // Surface endpoint exists but user is not authenticated — redirect to login
      if (import.meta.client) {
        window.location.href = `${baseUrl}/login`
      }
      return { provide: { admin: null } }
    }
  } catch {
    // Surface not available — fall through to legacy bootstrap
  }

  if (useSurface && surfaceSession && surfaceCatalog) {
    const catalog: CatalogEntry[] = surfaceCatalog.map(entry => ({
      id: entry.id,
      label: entry.label,
      description: entry.description,
      group: entry.group,
      disabled: entry.disabled,
      capabilities: entry.capabilities,
    }))

    const transport = new AdminSurfaceTransportAdapter(surfacePath)

    const session: AdminSession = {
      account: surfaceSession.account,
      tenant: { ...surfaceSession.tenant, scopingStrategy: 'server' },
      features: surfaceSession.features,
    }

    // Build a synthetic bootstrap for backward compatibility
    const bootstrap: AdminBootstrap = {
      version: ADMIN_CONTRACT_VERSION,
      auth: { strategy: 'redirect', loginUrl: `${baseUrl}/login` },
      account: session.account,
      tenant: session.tenant,
      transport: { strategy: 'custom' },
      entities: catalog,
      features: session.features,
    }

    const auth = new BootstrapAuthAdapter(bootstrap)

    const runtime: AdminRuntime = {
      bootstrap,
      auth,
      transport,
      catalog,
      tenant: session.tenant,
    }

    return { provide: { admin: runtime } }
  }

  // ── Legacy bootstrap fallback ──────────────────────────────────

  let bootstrap: AdminBootstrap

  if (import.meta.client && window.__WAASEYAA_ADMIN__) {
    bootstrap = window.__WAASEYAA_ADMIN__
  } else {
    let response: AdminBootstrap | null = null
    let fetchError: unknown = null

    try {
      response = await $fetch<AdminBootstrap>(`${baseUrl}/admin/bootstrap`, {
        ignoreResponseError: true,
        credentials: 'include',
        onResponseError({ response: res }) {
          if (res.status === 401 || res.status === 403) {
            // Auth failure — will redirect to login below
          }
        },
      })
    } catch (err) {
      fetchError = err
    }

    if (fetchError) {
      // Network, CORS, or timeout error — not an auth issue
      const message = fetchError instanceof Error ? fetchError.message : String(fetchError)
      console.error('[waaseyaa:admin] Bootstrap fetch failed (network/CORS/timeout):', message)
      throw createError({
        statusCode: 503,
        message: `Unable to reach the admin API: ${message}`,
        fatal: true,
      })
    }

    if (!response) {
      // HTTP error (401/403) — redirect to login
      if (import.meta.client) {
        window.location.href = `${baseUrl}/login`
      }
      return { provide: { admin: null } }
    }
    bootstrap = response
  }

  // Validate contract version
  if (bootstrap.version !== ADMIN_CONTRACT_VERSION) {
    throw createError({
      statusCode: 500,
      message: `Admin contract version mismatch: expected ${ADMIN_CONTRACT_VERSION}, got ${bootstrap.version}`,
      fatal: true,
    })
  }

  // Instantiate legacy adapters
  const auth = new BootstrapAuthAdapter(bootstrap)
  const apiPath = bootstrap.transport.apiPath ?? '/api'
  const resolvedApiPath = `${baseUrl}${apiPath}`
  const transport = new JsonApiTransportAdapter(resolvedApiPath, bootstrap.tenant)

  const runtime: AdminRuntime = {
    bootstrap,
    auth,
    transport,
    catalog: bootstrap.entities,
    tenant: bootstrap.tenant,
  }

  return { provide: { admin: runtime } }
})

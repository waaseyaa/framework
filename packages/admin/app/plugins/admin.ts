import { SessionAuthAdapter } from '../adapters/SessionAuthAdapter'
import { AdminSurfaceTransportAdapter } from '../adapters/AdminSurfaceTransportAdapter'
import { isPublicAuthPath } from '../runtime/publicAuthPaths'
import type { AdminRuntime, AdminAuthConfig } from '../contracts/runtime'
import type { CatalogEntry } from '../contracts/catalog'
import type {
  AdminSurfaceCatalogEntry as SurfaceCatalogEntry,
  AdminSurfaceResult as SurfaceResult,
  AdminSurfaceSession as SurfaceSession,
} from '../../../admin-surface/contract/types'
import { normalizeAppBaseURL } from '../runtime/normalizeAppBaseURL'
import { adminSurfaceFetchUrl } from '../runtime/adminSurfaceRoutes'
import { normalizeSurfaceUi } from '../runtime/normalizeSurfaceUi'

export default defineNuxtPlugin(async (): Promise<{ provide: { admin: AdminRuntime | null } }> => {
  const config = useRuntimeConfig()
  const normalizedAppBase = normalizeAppBaseURL(config.app.baseURL)
  const adminPathBase = normalizedAppBase.replace(/\/+$/, '') || ''
  const currentUser = useState<SurfaceSession['account'] | null>('waaseyaa.auth.user', () => null)
  const authChecked = useState<boolean>('waaseyaa.auth.checked', () => false)

  function syncAuthState(account: SurfaceSession['account'] | null, checked: boolean) {
    currentUser.value = account
    authChecked.value = checked
  }

  // ── Skip auth check on public auth pages (prevents redirect loop) ─────
  if (isPublicAuthPath(window.location.pathname, adminPathBase)) {
    syncAuthState(null, false)
    return { provide: { admin: null } }
  }

  // ── Fetch session from AdminSurface API ──────────────────────────

  let surfaceSession: SurfaceSession | null = null
  let surfaceCatalog: SurfaceCatalogEntry[] | null = null

  try {
    const sessionRes = await $fetch<SurfaceResult<SurfaceSession>>(
      adminSurfaceFetchUrl(normalizedAppBase, 'admin_surface.session'),
      {
        ignoreResponseError: true,
        credentials: 'include',
      },
    )

    if (sessionRes && sessionRes.ok && sessionRes.data) {
      surfaceSession = sessionRes.data

      const catalogRes = await $fetch<SurfaceResult<{ entities: SurfaceCatalogEntry[] }>>(
        adminSurfaceFetchUrl(normalizedAppBase, 'admin_surface.catalog'),
        {
          ignoreResponseError: true,
          credentials: 'include',
        },
      )
      if (catalogRes && catalogRes.ok && catalogRes.data) {
        surfaceCatalog = catalogRes.data.entities
      }
    } else if (sessionRes && !sessionRes.ok && sessionRes.error?.status === 401) {
      syncAuthState(null, true)
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
    syncAuthState(null, true)
    await navigateTo('/login', { replace: true })
    return { provide: { admin: null } }
  }

  // ── Build runtime from surface response ──────────────────────────

  const catalog: CatalogEntry[] = surfaceCatalog.map((entry) => {
    const description = 'description' in entry && typeof entry.description === 'string'
      ? entry.description
      : undefined
    const disabled = 'disabled' in entry && typeof entry.disabled === 'boolean'
      ? entry.disabled
      : undefined

    return {
      id: entry.id,
      label: entry.label,
      group: entry.group,
      fields: entry.fields,
      actions: entry.actions,
      capabilities: entry.capabilities,
      ...('reference' in entry && entry.reference !== undefined ? { reference: entry.reference } : {}),
      ...(description !== undefined ? { description } : {}),
      ...(disabled !== undefined ? { disabled } : {}),
    }
  })

  syncAuthState(surfaceSession.account, true)

  const account = surfaceSession.account
  const tenant = { ...surfaceSession.tenant, scopingStrategy: 'server' as const }
  const authConfig: AdminAuthConfig = { strategy: 'redirect', loginUrl: '/login' }

  const auth = new SessionAuthAdapter(account, tenant, authConfig, surfaceSession.features)
  const transport = new AdminSurfaceTransportAdapter(normalizedAppBase)

  const runtime: AdminRuntime = {
    auth,
    authConfig,
    transport,
    catalog,
    tenant,
    account,
    features: surfaceSession.features,
    capabilities: surfaceSession.capabilities,
    ui: normalizeSurfaceUi(surfaceSession.ui),
  }

  return { provide: { admin: runtime } }
})

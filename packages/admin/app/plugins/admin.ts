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

export default defineNuxtPlugin(async (): Promise<{ provide: { admin: AdminRuntime | null } }> => {
  const config = useRuntimeConfig()
  const baseUrl = (config.public.baseUrl as string) || ''
  const surfacePath = `${baseUrl}/_surface`
  const currentUser = useState<SurfaceSession['account'] | null>('waaseyaa.auth.user', () => null)
  const authChecked = useState<boolean>('waaseyaa.auth.checked', () => false)

  function syncAuthState(account: SurfaceSession['account'] | null, checked: boolean) {
    currentUser.value = account
    authChecked.value = checked
  }

  // ── Skip auth check on public auth pages (prevents redirect loop) ─────
  if (import.meta.client) {
    if (isPublicAuthPath(window.location.pathname, baseUrl)) {
      syncAuthState(null, false)
      return { provide: { admin: null } }
    }
  }
  if (import.meta.server) {
    const route = useRoute()
    if (isPublicAuthPath(route.path, baseUrl)) {
      syncAuthState(null, false)
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
      ...(description !== undefined ? { description } : {}),
      ...(disabled !== undefined ? { disabled } : {}),
    }
  })

  syncAuthState(surfaceSession.account, true)

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

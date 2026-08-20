import { afterEach, describe, it, expect, vi } from 'vitest'
import type { AdminRuntime } from '~/contracts/runtime'

describe('admin plugin', () => {
  it('provides AdminRuntime with expected shape via $admin', () => {
    const { $admin } = useNuxtApp()
    expect($admin).toBeTruthy()
    if (!$admin) {
      throw new Error('Expected admin runtime to be available in plugin test.')
    }
    expect($admin.transport).toBeTruthy()
    expect($admin.catalog).toBeInstanceOf(Array)
    expect($admin.tenant).toBeTruthy()
    expect($admin.account).toBeTruthy()
    expect($admin.ui.headerLinks).toEqual([])
    expect($admin.ui.sidebarItems).toEqual([])
  })

  it('catalog contains entity types from surface API', () => {
    const { $admin } = useNuxtApp()
    if (!$admin) {
      throw new Error('Expected admin runtime to be available in plugin test.')
    }
    expect($admin.catalog.length).toBeGreaterThan(0)
    expect($admin.catalog[0].id).toBe('user')
    expect($admin.catalog[0]).toMatchObject({
      id: 'user',
      label: 'User',
      description: 'User accounts',
      disabled: false,
      fields: [],
      actions: [],
    })
    expect('keys' in $admin.catalog[0]).toBe(false)
  })

  it('preserves declared actions on runtime catalog entries', () => {
    const { $admin } = useNuxtApp() as unknown as { $admin: AdminRuntime }
    const node = $admin.catalog.find(entry => entry.id === 'node')

    expect(node).toBeTruthy()
    expect(node?.actions).toBeInstanceOf(Array)
    expect(node?.actions).toContainEqual({ id: 'board-config', label: 'Board Config', scope: 'collection' })
  })

  it('threads the session capability projection into the runtime', () => {
    const { $admin } = useNuxtApp()
    if (!$admin) {
      throw new Error('Expected admin runtime to be available in plugin test.')
    }
    expect($admin.capabilities).toMatchObject({
      'mcp.approval.view': true,
      'mcp.approval.decide': false,
    })
  })

  it('hydrates shared auth state from the bootstrap session', () => {
    const { $admin } = useNuxtApp()
    if (!$admin) {
      throw new Error('Expected admin runtime to be available in plugin test.')
    }
    const currentUser = useState<typeof $admin.account | null>('waaseyaa.auth.user', () => null)
    const authChecked = useState<boolean>('waaseyaa.auth.checked', () => false)

    expect(currentUser.value).toEqual($admin.account)
    expect(authChecked.value).toBe(true)
  })
})

describe('admin plugin degraded bootstrap paths', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('skips bootstrap and clears auth state on public auth routes', async () => {
    vi.resetModules()

    const fetchSpy = vi.fn()
    window.history.replaceState({}, '', '/admin/login/')

    vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
    vi.stubGlobal('useRuntimeConfig', () => ({
      app: { baseURL: '/admin/' },
      public: { baseUrl: '/admin' },
    }))
    vi.stubGlobal('$fetch', fetchSpy)

    const plugin = (await import('~/plugins/admin')).default as () => Promise<{ provide: { admin: AdminRuntime | null } }>
    const result = await plugin()
    const currentUser = useState('waaseyaa.auth.user', () => null)
    const authChecked = useState('waaseyaa.auth.checked', () => true)

    expect(result.provide.admin).toBeNull()
    expect(currentUser.value).toBeNull()
    expect(authChecked.value).toBe(false)
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('redirects to login and marks auth checked on 401 session bootstrap', async () => {
    vi.resetModules()

    window.history.replaceState({}, '', '/admin')

    vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
    vi.stubGlobal('useRuntimeConfig', () => ({
      app: { baseURL: '/admin/' },
      public: { baseUrl: '/admin' },
    }))
    vi.stubGlobal('$fetch', vi.fn(async () => ({ ok: false, error: { status: 401 } })))

    const plugin = (await import('~/plugins/admin')).default as () => Promise<{ provide: { admin: AdminRuntime | null } }>
    const result = await plugin()
    const currentUser = useState('waaseyaa.auth.user', () => ({ id: 'stale' }))
    const authChecked = useState('waaseyaa.auth.checked', () => false)

    expect(result.provide.admin).toBeNull()
    expect(currentUser.value).toBeNull()
    expect(authChecked.value).toBe(true)
  })

  it('reports embedded session expiry to the same-origin parent before redirecting', async () => {
    vi.resetModules()

    window.history.replaceState({}, '', '/admin/entity-editor-embed/node/42')
    const originalParent = window.parent
    const postMessage = vi.fn()
    Object.defineProperty(window, 'parent', { configurable: true, value: { postMessage } })

    vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
    vi.stubGlobal('useRuntimeConfig', () => ({
      app: { baseURL: '/admin/' },
      public: { baseUrl: '/admin' },
    }))
    vi.stubGlobal('$fetch', vi.fn(async () => ({ ok: false, error: { status: 401 } })))
    vi.stubGlobal('navigateTo', vi.fn(async () => {}))

    try {
      const plugin = (await import('~/plugins/admin')).default as () => Promise<{ provide: { admin: AdminRuntime | null } }>
      await plugin()

      expect(postMessage).toHaveBeenCalledWith({
        schema: 'waaseyaa.admin.embed.lifecycle.v1',
        event: 'failure',
        surface: 'entity-editor',
        entityType: 'node',
        entityId: '42',
        failure: { kind: 'session-expired', status: 401 },
      }, window.location.origin)
    } finally {
      Object.defineProperty(window, 'parent', { configurable: true, value: originalParent })
    }
  })

  it('redirects to login when the session succeeds but catalog bootstrap is unavailable', async () => {
    vi.resetModules()

    window.history.replaceState({}, '', '/admin')

    vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
    vi.stubGlobal('useRuntimeConfig', () => ({
      app: { baseURL: '/admin/' },
      public: { baseUrl: '/admin' },
    }))
    vi.stubGlobal('$fetch', vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        data: {
          account: { id: '1', name: 'Admin', email: 'admin@example.com', roles: ['admin'] },
          tenant: { id: 'default', name: 'Waaseyaa' },
          policies: ['admin'],
          features: {},
        },
      })
      .mockResolvedValueOnce({ ok: false, error: { status: 503 } }))

    const plugin = (await import('~/plugins/admin')).default as () => Promise<{ provide: { admin: AdminRuntime | null } }>
    const result = await plugin()
    const currentUser = useState('waaseyaa.auth.user', () => ({ id: 'stale' }))
    const authChecked = useState('waaseyaa.auth.checked', () => false)

    expect(result.provide.admin).toBeNull()
    expect(currentUser.value).toBeNull()
    expect(authChecked.value).toBe(true)
  })

  it('collapses duplicate slashes in app baseURL for surface requests', async () => {
    vi.resetModules()

    window.history.replaceState({}, '', '/admin/entities')

    const fetchSpy = vi.fn(async () => ({ ok: false, error: { status: 401 } }))
    vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
    vi.stubGlobal('useRuntimeConfig', () => ({
      app: { baseURL: '//admin//' },
      public: { baseUrl: '/admin' },
    }))
    vi.stubGlobal('$fetch', fetchSpy)
    vi.stubGlobal('navigateTo', vi.fn(async () => {}))

    const plugin = (await import('~/plugins/admin')).default as () => Promise<{ provide: { admin: AdminRuntime | null } }>
    await plugin()

    expect(fetchSpy).toHaveBeenCalledWith(
      '/admin/_surface/session',
      expect.objectContaining({ credentials: 'include' }),
    )
  })

  it('throws a fatal 503 when the surface API is unreachable', async () => {
    vi.resetModules()
    window.history.replaceState({}, '', '/admin')

    vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
    vi.stubGlobal('useRuntimeConfig', () => ({
      app: { baseURL: '/admin/' },
      public: { baseUrl: '/admin' },
    }))
    vi.stubGlobal('$fetch', vi.fn(async () => {
      throw new Error('network down')
    }))

    const plugin = (await import('~/plugins/admin')).default as () => Promise<{ provide: { admin: AdminRuntime | null } }>

    await expect(plugin()).rejects.toMatchObject({
      statusCode: 503,
      message: 'Unable to reach the admin API. Ensure the PHP backend is running with an AdminSurfaceHost registered.',
      fatal: true,
    })
  })
})

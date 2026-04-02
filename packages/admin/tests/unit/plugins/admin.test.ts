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
    vi.stubGlobal('useRuntimeConfig', () => ({ public: { baseUrl: '/admin' } }))
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
    vi.stubGlobal('useRuntimeConfig', () => ({ public: { baseUrl: '/admin' } }))
    vi.stubGlobal('$fetch', vi.fn(async () => ({ ok: false, error: { status: 401 } })))

    const plugin = (await import('~/plugins/admin')).default as () => Promise<{ provide: { admin: AdminRuntime | null } }>
    const result = await plugin()
    const currentUser = useState('waaseyaa.auth.user', () => ({ id: 'stale' }))
    const authChecked = useState('waaseyaa.auth.checked', () => false)

    expect(result.provide.admin).toBeNull()
    expect(currentUser.value).toBeNull()
    expect(authChecked.value).toBe(true)
  })

  it('redirects to login when the session succeeds but catalog bootstrap is unavailable', async () => {
    vi.resetModules()

    window.history.replaceState({}, '', '/admin')

    vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
    vi.stubGlobal('useRuntimeConfig', () => ({ public: { baseUrl: '/admin' } }))
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

  it('throws a fatal 503 when the surface API is unreachable', async () => {
    vi.resetModules()
    window.history.replaceState({}, '', '/admin')

    vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
    vi.stubGlobal('useRuntimeConfig', () => ({ public: { baseUrl: '/admin' } }))
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

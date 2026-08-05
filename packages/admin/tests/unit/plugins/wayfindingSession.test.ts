import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import type { AdminRuntime } from '~/contracts/runtime'

type PluginApp = {
  $admin: AdminRuntime | null
  vueApp: { onUnmount: (callback: () => void) => void }
}

async function loadPlugin() {
  vi.resetModules()
  vi.stubGlobal('defineNuxtPlugin', (plugin: unknown) => plugin)
  return (await import('~/plugins/wayfindingSession.client')).default as (app: PluginApp) => void
}

function runtime(wayfinding: unknown): AdminRuntime {
  return {
    ...(useNuxtApp().$admin as AdminRuntime),
    features: { wayfinding } as Record<string, boolean>,
  }
}

describe('wayfinding session plugin activation boundary', () => {
  afterEach(() => {
    document.documentElement.removeAttribute('data-wf-session')
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('does not probe the optional endpoint before authentication', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)
    useState('waaseyaa.auth.user', () => null).value = null

    const plugin = await loadPlugin()
    plugin({ $admin: null, vueApp: { onUnmount: vi.fn() } })
    await flushPromises()

    expect(fetchSpy).not.toHaveBeenCalled()
    expect(document.documentElement.hasAttribute('data-wf-session')).toBe(false)
  })

  it('requires the exact installed feature flag before probing', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)
    const admin = runtime('yes')
    useState('waaseyaa.auth.user', () => admin.account).value = admin.account

    const plugin = await loadPlugin()
    plugin({ $admin: admin, vueApp: { onUnmount: vi.fn() } })
    await flushPromises()

    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('aborts an in-flight request and clears the token when authentication is lost', async () => {
    let resolveFetch!: (response: Response) => void
    let requestSignal: AbortSignal | null = null
    const fetchSpy = vi.fn((_url: string, init?: RequestInit) => new Promise<Response>((resolve) => {
      resolveFetch = resolve
      requestSignal = init?.signal as AbortSignal | null
    }))
    vi.stubGlobal('fetch', fetchSpy)
    const admin = runtime(true)
    const currentUser = useState('waaseyaa.auth.user', () => admin.account)
    currentUser.value = admin.account

    const plugin = await loadPlugin()
    plugin({ $admin: admin, vueApp: { onUnmount: vi.fn() } })
    await Promise.resolve()
    currentUser.value = null
    expect(requestSignal).toBeInstanceOf(AbortSignal)
    expect(requestSignal?.aborted).toBe(true)
    resolveFetch(new Response(JSON.stringify({ data: { sessionToken: 'late-token' } }), { status: 200 }))
    await flushPromises()

    expect(fetchSpy).toHaveBeenCalledTimes(1)
    expect(document.documentElement.hasAttribute('data-wf-session')).toBe(false)
  })
})

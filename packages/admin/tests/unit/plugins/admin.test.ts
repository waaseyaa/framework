import { describe, it, expect } from 'vitest'
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

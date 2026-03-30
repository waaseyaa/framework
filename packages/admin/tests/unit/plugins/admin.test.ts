import { describe, it, expect } from 'vitest'
import type { AdminRuntime } from '~/contracts/runtime'

describe('admin plugin', () => {
  it('provides AdminRuntime with expected shape via $admin', () => {
    const { $admin } = useNuxtApp() as unknown as { $admin: AdminRuntime }
    expect($admin).toBeTruthy()
    expect($admin.transport).toBeTruthy()
    expect($admin.catalog).toBeInstanceOf(Array)
    expect($admin.tenant).toBeTruthy()
    expect($admin.account).toBeTruthy()
  })

  it('catalog contains entity types from surface API', () => {
    const { $admin } = useNuxtApp() as unknown as { $admin: AdminRuntime }
    expect($admin.catalog.length).toBeGreaterThan(0)
    expect($admin.catalog[0].id).toBe('user')
  })
})

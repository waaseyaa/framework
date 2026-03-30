// packages/admin/tests/unit/composables/useEntity.test.ts
// The useEntity composable delegates to $admin.transport (AdminSurfaceTransportAdapter).
// The transport calls /_surface/* endpoints with SurfaceResult<T> envelope { ok, data }.
import { describe, it, expect, vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import { useEntity } from '~/composables/useEntity'

describe('useEntity (adapter-backed)', () => {
  it('list delegates to transport and returns result', async () => {
    registerEndpoint('/_surface/node', () => ({
      ok: true,
      data: {
        entities: [{ type: 'node', id: '1', attributes: { title: 'Hello' } }],
        total: 1,
        offset: 0,
        limit: 25,
      },
    }))
    const { list } = useEntity()
    const result = await list('node')
    expect(result.data).toHaveLength(1)
    expect(result.data[0].attributes.title).toBe('Hello')
  })

  it('get delegates to transport and returns resource', async () => {
    registerEndpoint('/_surface/node/5', () => ({
      ok: true,
      data: { type: 'node', id: '5', attributes: { title: 'Post' } },
    }))
    const { get } = useEntity()
    const result = await get('node', '5')
    expect(result.id).toBe('5')
    expect(result.attributes.title).toBe('Post')
  })

  it('create delegates to transport', async () => {
    registerEndpoint('/_surface/node/action/create', {
      method: 'POST',
      handler: () => ({
        ok: true,
        data: { type: 'node', id: '6', attributes: { title: 'New' } },
      }),
    })
    const { create } = useEntity()
    const result = await create('node', { title: 'New' })
    expect(result.id).toBe('6')
  })

  it('search returns empty array for short queries', async () => {
    const { search } = useEntity()
    const result = await search('user', 'name', 'j')
    expect(result).toEqual([])
  })
})

import { describe, expect, it, vi } from 'vitest'
import {
  EMBED_LIFECYCLE_SCHEMA,
  EMBED_LIFECYCLE_SCHEMA_V2,
  classifyEmbedFailure,
  embedIdentityFromPath,
  postEmbedLifecycle,
} from '~/runtime/embedLifecycle'

describe('embed lifecycle protocol', () => {
  it('posts a versioned identity-only envelope to the exact origin', () => {
    const postMessage = vi.fn()
    const child = {
      parent: { postMessage },
      location: { origin: 'https://example.test' },
    } as unknown as Window

    postEmbedLifecycle({
      event: 'dirty',
      surface: 'entity-editor',
      entityType: 'node',
      entityId: '42',
      dirty: true,
    }, child)

    expect(postMessage).toHaveBeenCalledWith({
      schema: EMBED_LIFECYCLE_SCHEMA,
      event: 'dirty',
      surface: 'entity-editor',
      entityType: 'node',
      entityId: '42',
      dirty: true,
    }, 'https://example.test')
  })

  it('does nothing outside a frame', () => {
    const postMessage = vi.fn()
    const child = { location: { origin: 'https://example.test' } } as unknown as Window
    Object.assign(child, { parent: child, postMessage })

    postEmbedLifecycle({ event: 'ready', surface: 'page-builder', surfaceId: 'page', entityId: '42' }, child)

    expect(postMessage).not.toHaveBeenCalled()
  })

  it('reconstructs the envelope so accidental content cannot cross the boundary', () => {
    const postMessage = vi.fn()
    const child = {
      parent: { postMessage },
      location: { origin: 'https://example.test' },
    } as unknown as Window

    postEmbedLifecycle({
      event: 'saved',
      surface: 'entity-editor',
      entityType: 'node',
      entityId: '42',
      attributes: { title: 'private content' },
    } as never, child)

    expect(postMessage.mock.calls[0]?.[0]).not.toHaveProperty('attributes')
  })

  it('emits an authoritative transition only in the v2 observation envelope', () => {
    const postMessage = vi.fn()
    const child = {
      parent: { postMessage },
      location: { origin: 'https://example.test' },
    } as unknown as Window

    postEmbedLifecycle({
      event: 'transitioned',
      surface: 'entity-editor',
      entityType: 'node',
      entityId: '42',
      transition: { state: 'published', publicChanged: true },
    }, child)

    expect(postMessage).toHaveBeenCalledTimes(1)
    expect(postMessage).toHaveBeenCalledWith({
      schema: EMBED_LIFECYCLE_SCHEMA_V2,
      event: 'transitioned',
      surface: 'entity-editor',
      entityType: 'node',
      entityId: '42',
      transition: { state: 'published', publicChanged: true },
    }, 'https://example.test')
  })

  it.each([
    [401, 'session-expired'],
    [403, 'permission-denied'],
    [409, 'conflict'],
    [422, 'validation'],
    [428, 'server'],
    [503, 'server'],
  ] as const)('classifies HTTP %s without carrying response detail', (status, kind) => {
    expect(classifyEmbedFailure({ status, data: { secret: 'must not cross' } })).toEqual({ kind, status })
  })

  it('classifies a request with no response as a network failure', () => {
    expect(classifyEmbedFailure(new TypeError('Failed to fetch'))).toEqual({ kind: 'network' })
  })

  it('derives only canonical shell-free identities for bootstrap failures', () => {
    expect(embedIdentityFromPath('/admin/entity-editor-embed/node/create', '/admin')).toEqual({
      surface: 'entity-editor',
      entityType: 'node',
    })
    expect(embedIdentityFromPath('/admin/page-builder-embed/page/a%2Fb', '/admin')).toEqual({
      surface: 'page-builder',
      surfaceId: 'page',
      entityId: 'a/b',
    })
    expect(embedIdentityFromPath('/admin/node/42', '/admin')).toBeNull()
  })
})

import { describe, expect, it, vi } from 'vitest'
import {
  EMBED_LIFECYCLE_SCHEMA,
  classifyEmbedFailure,
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

  it.each([
    [401, 'session-expired'],
    [403, 'permission-denied'],
    [409, 'conflict'],
    [503, 'server'],
  ] as const)('classifies HTTP %s without carrying response detail', (status, kind) => {
    expect(classifyEmbedFailure({ status, data: { secret: 'must not cross' } })).toEqual({ kind, status })
  })

  it('classifies a request with no response as a network failure', () => {
    expect(classifyEmbedFailure(new TypeError('Failed to fetch'))).toEqual({ kind: 'network' })
  })
})

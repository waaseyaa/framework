import { describe, expect, it, vi } from 'vitest'
import {
  EMBED_LIFECYCLE_SCHEMA,
  EMBED_LIFECYCLE_SCHEMA_V2,
  classifyEmbedFailure,
  embedIdentityFromPath,
  postEmbedLifecycle,
  type EmbedLifecyclePayload,
} from '~/runtime/embedLifecycle'

function framedChild() {
  const postMessage = vi.fn()
  const child = {
    parent: { postMessage },
    location: { origin: 'https://example.test' },
  } as unknown as Window
  return { child, postMessage }
}

describe('embed lifecycle protocol', () => {
  it.each([
    {
      name: 'ready',
      payload: {
        event: 'ready' as const,
        surface: 'entity-editor' as const,
        entityType: 'node',
        entityId: '42',
      },
      schema: EMBED_LIFECYCLE_SCHEMA,
      expected: {
        schema: EMBED_LIFECYCLE_SCHEMA,
        event: 'ready',
        surface: 'entity-editor',
        entityType: 'node',
        entityId: '42',
      },
    },
    {
      name: 'dirty',
      payload: {
        event: 'dirty' as const,
        surface: 'entity-editor' as const,
        entityType: 'node',
        entityId: '42',
        dirty: true,
      },
      schema: EMBED_LIFECYCLE_SCHEMA,
      expected: {
        schema: EMBED_LIFECYCLE_SCHEMA,
        event: 'dirty',
        surface: 'entity-editor',
        entityType: 'node',
        entityId: '42',
        dirty: true,
      },
    },
    {
      name: 'saved',
      payload: {
        event: 'saved' as const,
        surface: 'entity-editor' as const,
        entityType: 'node',
        entityId: '42',
      },
      schema: EMBED_LIFECYCLE_SCHEMA,
      expected: {
        schema: EMBED_LIFECYCLE_SCHEMA,
        event: 'saved',
        surface: 'entity-editor',
        entityType: 'node',
        entityId: '42',
      },
    },
    {
      name: 'deleted',
      payload: {
        event: 'deleted' as const,
        surface: 'entity-editor' as const,
        entityType: 'node',
        entityId: '42',
      },
      schema: EMBED_LIFECYCLE_SCHEMA,
      expected: {
        schema: EMBED_LIFECYCLE_SCHEMA,
        event: 'deleted',
        surface: 'entity-editor',
        entityType: 'node',
        entityId: '42',
      },
    },
    {
      name: 'failure',
      payload: {
        event: 'failure' as const,
        surface: 'entity-editor' as const,
        entityType: 'node',
        entityId: '42',
        failure: { kind: 'permission-denied' as const, status: 403 },
      },
      schema: EMBED_LIFECYCLE_SCHEMA,
      expected: {
        schema: EMBED_LIFECYCLE_SCHEMA,
        event: 'failure',
        surface: 'entity-editor',
        entityType: 'node',
        entityId: '42',
        failure: { kind: 'permission-denied', status: 403 },
      },
    },
    {
      name: 'transitioned',
      payload: {
        event: 'transitioned' as const,
        surface: 'entity-editor' as const,
        entityType: 'node',
        entityId: '42',
        transition: { state: 'published', publicChanged: true },
      },
      schema: EMBED_LIFECYCLE_SCHEMA_V2,
      expected: {
        schema: EMBED_LIFECYCLE_SCHEMA_V2,
        event: 'transitioned',
        surface: 'entity-editor',
        entityType: 'node',
        entityId: '42',
        transition: { state: 'published', publicChanged: true },
      },
    },
  ])('delivers one $name message with the compatibility schema and payload', ({ payload, schema, expected }) => {
    const { child, postMessage } = framedChild()

    postEmbedLifecycle(payload as EmbedLifecyclePayload, child)

    expect(postMessage).toHaveBeenCalledTimes(1)
    expect(postMessage).toHaveBeenCalledWith(expected, 'https://example.test')
    expect(postMessage.mock.calls[0]?.[0].schema).toBe(schema)
    expect(postMessage.mock.calls.map((call) => call[0].schema)).toEqual([schema])
  })

  it('does nothing outside a frame', () => {
    const postMessage = vi.fn()
    const child = { location: { origin: 'https://example.test' } } as unknown as Window
    Object.assign(child, { parent: child, postMessage })

    postEmbedLifecycle({ event: 'ready', surface: 'page-builder', surfaceId: 'page', entityId: '42' }, child)

    expect(postMessage).not.toHaveBeenCalled()
  })

  it('reconstructs the envelope so accidental content cannot cross the boundary', () => {
    const { child, postMessage } = framedChild()

    postEmbedLifecycle({
      event: 'saved',
      surface: 'entity-editor',
      entityType: 'node',
      entityId: '42',
      attributes: { title: 'private content' },
    } as never, child)

    expect(postMessage).toHaveBeenCalledTimes(1)
    expect(postMessage.mock.calls[0]?.[0]).not.toHaveProperty('attributes')
  })

  it.each([
    [401, 'session-expired'],
    [403, 'permission-denied'],
    [409, 'conflict'],
    [412, 'conflict'],
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

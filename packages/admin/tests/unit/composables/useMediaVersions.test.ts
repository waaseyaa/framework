// packages/admin/tests/unit/composables/useMediaVersions.test.ts
// useMediaVersions fetches GET /api/media/{uuid}/versions (WP04, DIR-005).
// Mirrors useScheduledTasks.test.ts (M4B WP02) for shape and naming.
import { describe, it, expect, beforeEach } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const versionA = {
  vid: 2,
  media_uuid: 'media-abc',
  blob_uri: 'cas://aaaa',
  mime: 'image/png',
  size_bytes: 1024,
  sha256: 'a'.repeat(64),
  created_at: 1748000002,
  created_by: 1,
}

const versionB = {
  vid: 1,
  media_uuid: 'media-abc',
  blob_uri: 'cas://bbbb',
  mime: 'image/jpeg',
  size_bytes: 2048,
  sha256: 'b'.repeat(64),
  created_at: 1748000001,
  created_by: 1,
}

let storedVersions: typeof versionA[] = []

registerEndpoint('/api/media/media-abc/versions', () => ({
  data: storedVersions,
  meta: { total: storedVersions.length },
}))

registerEndpoint('/api/media/no-such-media/versions', () => ({
  data: [],
  meta: { total: 0 },
}))

beforeEach(() => {
  storedVersions = [{ ...versionA }, { ...versionB }]
})

describe('useMediaVersions', () => {
  it('starts empty with no error and not loading', async () => {
    const { useMediaVersions } = await import('~/composables/useMediaVersions')
    const { versions, loading, error } = useMediaVersions()
    expect(versions.value).toEqual([])
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates versions + total', async () => {
    const { useMediaVersions } = await import('~/composables/useMediaVersions')
    const { versions, total, loading, error, fetchVersions } = useMediaVersions()
    await fetchVersions('media-abc')
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(versions.value).toHaveLength(2)
    expect(versions.value[0].vid).toBe(2)
    expect(versions.value[1].vid).toBe(1)
    expect(total.value).toBe(2)
  })

  it('returns empty array for an unknown media uuid', async () => {
    const { useMediaVersions } = await import('~/composables/useMediaVersions')
    const { versions, total, fetchVersions } = useMediaVersions()
    await fetchVersions('no-such-media')
    expect(versions.value).toEqual([])
    expect(total.value).toBe(0)
  })

  it('exposes sha256 and mime of each version', async () => {
    const { useMediaVersions } = await import('~/composables/useMediaVersions')
    const { versions, fetchVersions } = useMediaVersions()
    await fetchVersions('media-abc')
    const first = versions.value[0]
    expect(first.sha256).toHaveLength(64)
    expect(first.mime).toBe('image/png')
  })
})

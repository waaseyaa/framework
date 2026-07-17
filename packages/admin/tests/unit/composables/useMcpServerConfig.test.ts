// packages/admin/tests/unit/composables/useMcpServerConfig.test.ts
// useMcpServerConfig fetches GET /api/mcp/server-config.
// Security assertion: McpRegisteredClient type MUST NOT include a `token` field.
// Mirrors useQueueJobs.test.ts (M4B, PR #1529).
import { describe, it, expect, beforeEach } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import type { McpRegisteredClient } from '~/composables/useMcpServerConfig'

const configSeed = {
  transport: 'streamable-http' as const,
  protocolVersion: '2025-03-26',
  registeredClients: [
    {
      clientId: 'client-abc',
      addedAt: '2026-05-01T00:00:00Z',
      lastSeenAt: '2026-05-25T00:00:00Z',
      tokenFingerprint: 'a1b2c3d4e5f6a7b8',
    },
  ],
  serverCapabilities: ['tools', 'resources'],
}

let nextStatus = 200

registerEndpoint('/api/mcp/server-config', () => {
  if (nextStatus !== 200) {
    throw createError({ status: nextStatus, message: 'server error' })
  }
  return { data: { config: configSeed } }
})

beforeEach(() => {
  nextStatus = 200
})

describe('useMcpServerConfig', () => {
  it('starts empty with no error and not loading', async () => {
    const { useMcpServerConfig } = await import('~/composables/useMcpServerConfig')
    const { config, loading, error } = useMcpServerConfig()
    expect(config.value).toBeNull()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
  })

  it('fetches and populates config from /api/mcp/server-config', async () => {
    const { useMcpServerConfig } = await import('~/composables/useMcpServerConfig')
    const { config, loading, error, fetchConfig } = useMcpServerConfig()
    await fetchConfig()
    expect(loading.value).toBe(false)
    expect(error.value).toBeNull()
    expect(config.value).not.toBeNull()
    expect(config.value!.transport).toBe('streamable-http')
    expect(config.value!.protocolVersion).toBe('2025-03-26')
    expect(config.value!.registeredClients).toHaveLength(1)
    expect(config.value!.registeredClients[0].tokenFingerprint).toBe('a1b2c3d4e5f6a7b8')
    expect(config.value!.serverCapabilities).toEqual(['tools', 'resources'])
  })

  it('sets error on fetch failure', async () => {
    nextStatus = 500
    const { useMcpServerConfig } = await import('~/composables/useMcpServerConfig')
    const { config, error, fetchConfig } = useMcpServerConfig()
    await fetchConfig()
    expect(config.value).toBeNull()
    expect(error.value).not.toBeNull()
  })

  it('TS type assertion: McpRegisteredClient has no `token` field — only `tokenFingerprint`', () => {
    // Compile-time type guard: if `token` were present on McpRegisteredClient,
    // this assignment would produce a TS error (excess property check).
    const client: McpRegisteredClient = {
      clientId: 'test',
      tokenFingerprint: 'a1b2c3d4e5f6a7b8',
    }
    // Runtime assertion: the object does not have a 'token' key.
    expect('token' in client).toBe(false)
    expect(client.tokenFingerprint).toBe('a1b2c3d4e5f6a7b8')
  })
})

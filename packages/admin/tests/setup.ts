// packages/admin/tests/setup.ts
// Global test setup — provides mock /admin/_surface/* endpoints for the admin plugin.
// The admin plugin fetches /admin/_surface/session then /admin/_surface/catalog to build AdminRuntime.
// Individual tests can override $fetch or $admin as needed.
import { vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'
import type { AdminSurfaceCatalogEntry } from '../../admin-surface/contract/types'

const catalogEntities: AdminSurfaceCatalogEntry[] = [
  {
    id: 'user',
    label: 'User',
    description: 'User accounts',
    disabled: false,
    capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'node',
    label: 'Content',
    description: 'Content entries',
    disabled: false,
    capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
    fields: [],
    actions: [{ id: 'board-config', label: 'Board Config', scope: 'collection' }],
  },
  {
    id: 'node_type',
    label: 'Content Type',
    capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'taxonomy_term',
    label: 'Taxonomy Term',
    capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'taxonomy_vocabulary',
    label: 'Taxonomy Vocabulary',
    capabilities: { list: true, get: true, create: false, update: true, delete: false, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'media',
    label: 'Media',
    capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'media_type',
    label: 'Media Type',
    capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'path_alias',
    label: 'Path Alias',
    capabilities: { list: true, get: true, create: false, update: true, delete: true, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'menu',
    label: 'Menu',
    capabilities: { list: true, get: true, create: true, update: true, delete: false, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'menu_link',
    label: 'Menu Link',
    capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'workflow',
    label: 'Workflow',
    capabilities: { list: true, get: true, create: false, update: true, delete: false, schema: true },
    fields: [],
    actions: [],
  },
  {
    id: 'pipeline',
    label: 'Pipeline',
    capabilities: { list: true, get: true, create: false, update: true, delete: false, schema: true },
    fields: [],
    actions: [],
  },
]

// /admin/_surface/session — the admin plugin checks this first
registerEndpoint('/admin/_surface/session', () => ({
  ok: true,
  data: {
    account: { id: '1', name: 'Admin', email: 'admin@example.com', roles: ['admin'] },
    tenant: { id: 'default', name: 'Waaseyaa' },
    policies: ['admin'],
    features: {},
    // Server-authoritative capability projection (bounded host allowlist).
    // 'hostile.truthy' pins the fail-closed contract: can() must only honor
    // an exact boolean true, never a truthy non-boolean from a hostile host.
    capabilities: {
      'mcp.approval.view': true,
      'mcp.approval.decide': false,
      'hostile.truthy': 1 as unknown as boolean,
    },
  },
}))

// /admin/_surface/catalog — fetched after a successful session
registerEndpoint('/admin/_surface/catalog', () => ({
  ok: true,
  data: {
    entities: catalogEntities,
  },
}))

// packages/admin/tests/setup.ts
// Global test setup — provides mock /admin/_surface/* endpoints for the admin plugin.
// The admin plugin fetches /admin/_surface/session then /admin/_surface/catalog to build AdminRuntime.
// Individual tests can override $fetch or $admin as needed.
import { vi } from 'vitest'
import { registerEndpoint } from '@nuxt/test-utils/runtime'

const defaultCaps = { list: true, get: true, create: true, update: true, delete: true, schema: true }

const catalogEntities = [
  { id: 'user', label: 'User', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'node', label: 'Content', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'node_type', label: 'Content Type', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'taxonomy_term', label: 'Taxonomy Term', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'taxonomy_vocabulary', label: 'Taxonomy Vocabulary', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'media', label: 'Media', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'media_type', label: 'Media Type', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'path_alias', label: 'Path Alias', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'menu', label: 'Menu', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'menu_link', label: 'Menu Link', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'workflow', label: 'Workflow', capabilities: defaultCaps, fields: [], actions: [] },
  { id: 'pipeline', label: 'Pipeline', capabilities: defaultCaps, fields: [], actions: [] },
]

// /admin/_surface/session — the admin plugin checks this first
registerEndpoint('/admin/_surface/session', () => ({
  ok: true,
  data: {
    account: { id: '1', name: 'Admin', email: 'admin@example.com', roles: ['admin'] },
    tenant: { id: 'default', name: 'Waaseyaa' },
    policies: ['admin'],
    features: {},
  },
}))

// /admin/_surface/catalog — fetched after a successful session
registerEndpoint('/admin/_surface/catalog', () => ({
  ok: true,
  data: {
    entities: catalogEntities,
  },
}))

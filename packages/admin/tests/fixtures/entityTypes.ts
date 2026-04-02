// packages/admin/tests/fixtures/entityTypes.ts
import type { CatalogEntry } from '~/contracts'

const defaultCaps = { list: true, get: true, create: true, update: true, delete: true, schema: true }

export const entityTypes: CatalogEntry[] = [
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
  { id: 'note', label: 'Note', capabilities: defaultCaps, fields: [], actions: [] },
]

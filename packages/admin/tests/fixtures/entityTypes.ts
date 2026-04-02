// packages/admin/tests/fixtures/entityTypes.ts
import type { CatalogEntry } from '~/contracts'

export const entityTypes: CatalogEntry[] = [
  { id: 'user', label: 'User', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true }, fields: [], actions: [] },
  { id: 'node', label: 'Content', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true }, fields: [], actions: [] },
  { id: 'node_type', label: 'Content Type', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true }, fields: [], actions: [] },
  { id: 'taxonomy_term', label: 'Taxonomy Term', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true }, fields: [], actions: [] },
  { id: 'taxonomy_vocabulary', label: 'Taxonomy Vocabulary', capabilities: { list: true, get: true, create: false, update: true, delete: false, schema: true }, fields: [], actions: [] },
  { id: 'media', label: 'Media', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true }, fields: [], actions: [] },
  { id: 'media_type', label: 'Media Type', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true }, fields: [], actions: [] },
  { id: 'path_alias', label: 'Path Alias', capabilities: { list: true, get: true, create: false, update: true, delete: true, schema: true }, fields: [], actions: [] },
  { id: 'menu', label: 'Menu', capabilities: { list: true, get: true, create: true, update: true, delete: false, schema: true }, fields: [], actions: [] },
  { id: 'menu_link', label: 'Menu Link', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true }, fields: [], actions: [] },
  { id: 'workflow', label: 'Workflow', capabilities: { list: true, get: true, create: false, update: true, delete: false, schema: true }, fields: [], actions: [] },
  { id: 'pipeline', label: 'Pipeline', capabilities: { list: true, get: true, create: false, update: true, delete: false, schema: true }, fields: [], actions: [] },
  { id: 'note', label: 'Note', capabilities: { list: true, get: true, create: true, update: true, delete: true, schema: true }, fields: [], actions: [] },
]

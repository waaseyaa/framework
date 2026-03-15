// packages/admin/tests/fixtures/entityTypes.ts
import type { CatalogEntry } from '~/contracts'

const defaultCaps = { list: true, get: true, create: true, update: true, delete: true, schema: true }

export const entityTypes: CatalogEntry[] = [
  { id: 'user', label: 'User', keys: { id: 'id', label: 'name' }, capabilities: defaultCaps },
  { id: 'node', label: 'Content', keys: { id: 'id', label: 'title' }, capabilities: defaultCaps },
  { id: 'node_type', label: 'Content Type', keys: { id: 'type', label: 'name' }, capabilities: defaultCaps },
  { id: 'taxonomy_term', label: 'Taxonomy Term', keys: { id: 'id', label: 'name' }, capabilities: defaultCaps },
  { id: 'taxonomy_vocabulary', label: 'Taxonomy Vocabulary', keys: { id: 'vid', label: 'name' }, capabilities: defaultCaps },
  { id: 'media', label: 'Media', keys: { id: 'id', label: 'name' }, capabilities: defaultCaps },
  { id: 'media_type', label: 'Media Type', keys: { id: 'type', label: 'name' }, capabilities: defaultCaps },
  { id: 'path_alias', label: 'Path Alias', keys: { id: 'id', label: 'alias' }, capabilities: defaultCaps },
  { id: 'menu', label: 'Menu', keys: { id: 'id', label: 'label' }, capabilities: defaultCaps },
  { id: 'menu_link', label: 'Menu Link', keys: { id: 'id', label: 'title' }, capabilities: defaultCaps },
  { id: 'workflow', label: 'Workflow', keys: { id: 'id', label: 'label' }, capabilities: defaultCaps },
  { id: 'pipeline', label: 'Pipeline', keys: { id: 'id', label: 'label' }, capabilities: defaultCaps },
]

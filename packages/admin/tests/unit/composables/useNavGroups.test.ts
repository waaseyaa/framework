// packages/admin/tests/unit/composables/useNavGroups.test.ts
import { describe, it, expect } from 'vitest'
import { groupEntityTypes, humanize } from '~/composables/useNavGroups'
import type { CatalogEntry } from '~/contracts'

const K = { id: 'id', label: 'label' }
const C = { list: true, get: true, create: true, update: true, delete: true, schema: true }

function entry(id: string, label: string): CatalogEntry {
  return { id, label, keys: K, capabilities: C, fields: [], actions: [] }
}

describe('humanize', () => {
  it('returns Other for empty string', () => {
    expect(humanize('')).toBe('Other')
  })

  it('converts underscore_key to Title Case', () => {
    expect(humanize('nav_group_elders')).toBe('Nav Group Elders')
  })
})

describe('groupEntityTypes', () => {
  it('places user into the people group', () => {
    const groups = groupEntityTypes([entry('user', 'User')])
    const people = groups.find((g) => g.key === 'people')
    expect(people?.entityTypes[0].id).toBe('user')
  })

  it('places node and node_type into the content group', () => {
    const groups = groupEntityTypes([
      entry('node', 'Content'),
      entry('node_type', 'Content Type'),
    ])
    const content = groups.find((g) => g.key === 'content')
    expect(content?.entityTypes.map((e) => e.id)).toEqual(['node', 'node_type'])
  })

  it('omits groups that have no matching entity types', () => {
    const groups = groupEntityTypes([entry('user', 'User')])
    const keys = groups.map((g) => g.key)
    expect(keys).toContain('people')
    expect(keys).not.toContain('content')
    expect(keys).not.toContain('taxonomy')
  })

  it('places unknown entity types into an other group', () => {
    const groups = groupEntityTypes([entry('custom_thing', 'Custom')])
    expect(groups).toHaveLength(1)
    expect(groups[0].key).toBe('other')
    expect(groups[0].entityTypes[0].id).toBe('custom_thing')
  })

  it('returns empty array for empty input', () => {
    expect(groupEntityTypes([])).toEqual([])
  })

  it('provides humanized fallback label for unknown group key', () => {
    const types: CatalogEntry[] = [
      { id: 'elder_profile', label: 'Elder Profile', keys: K, capabilities: C, group: 'elders', fields: [], actions: [] },
    ]
    const groups = groupEntityTypes(types)
    expect(groups).toHaveLength(1)
    expect(groups[0].key).toBe('elders')
    expect(groups[0].labelKey).toBe('nav_group_elders')
    expect(groups[0].label).toBe('Elders') // humanized fallback
  })

  it('handles all 12 registered entity types without an other group', () => {
    const all = [
      entry('user', 'User'),
      entry('node', 'Content'),
      entry('node_type', 'Content Type'),
      entry('taxonomy_term', 'Term'),
      entry('taxonomy_vocabulary', 'Vocabulary'),
      entry('media', 'Media'),
      entry('media_type', 'Media Type'),
      entry('path_alias', 'Path Alias'),
      entry('menu', 'Menu'),
      entry('menu_link', 'Menu Link'),
      entry('workflow', 'Workflow'),
      entry('pipeline', 'Pipeline'),
    ]
    const groups = groupEntityTypes(all)
    const keys = groups.map((g) => g.key)
    expect(keys).not.toContain('other')
    const total = groups.reduce((sum, g) => sum + g.entityTypes.length, 0)
    expect(total).toBe(12)
  })
})

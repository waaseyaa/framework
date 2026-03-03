// packages/admin/tests/unit/composables/useNavGroups.test.ts
import { describe, it, expect } from 'vitest'
import { groupEntityTypes } from '~/composables/useNavGroups'

describe('groupEntityTypes', () => {
  it('places user into the people group', () => {
    const groups = groupEntityTypes([{ id: 'user', label: 'User' }])
    const people = groups.find((g) => g.key === 'people')
    expect(people?.entityTypes).toEqual([{ id: 'user', label: 'User' }])
  })

  it('places node and node_type into the content group', () => {
    const groups = groupEntityTypes([
      { id: 'node', label: 'Content' },
      { id: 'node_type', label: 'Content Type' },
    ])
    const content = groups.find((g) => g.key === 'content')
    expect(content?.entityTypes.map((e) => e.id)).toEqual(['node', 'node_type'])
  })

  it('omits groups that have no matching entity types', () => {
    const groups = groupEntityTypes([{ id: 'user', label: 'User' }])
    const keys = groups.map((g) => g.key)
    expect(keys).toContain('people')
    expect(keys).not.toContain('content')
    expect(keys).not.toContain('taxonomy')
  })

  it('places unknown entity types into an other group', () => {
    const groups = groupEntityTypes([{ id: 'custom_thing', label: 'Custom' }])
    expect(groups).toHaveLength(1)
    expect(groups[0].key).toBe('other')
    expect(groups[0].entityTypes).toEqual([{ id: 'custom_thing', label: 'Custom' }])
  })

  it('returns empty array for empty input', () => {
    expect(groupEntityTypes([])).toEqual([])
  })

  it('handles all 12 registered entity types without an other group', () => {
    const all = [
      { id: 'user', label: 'User' },
      { id: 'node', label: 'Content' },
      { id: 'node_type', label: 'Content Type' },
      { id: 'taxonomy_term', label: 'Term' },
      { id: 'taxonomy_vocabulary', label: 'Vocabulary' },
      { id: 'media', label: 'Media' },
      { id: 'media_type', label: 'Media Type' },
      { id: 'path_alias', label: 'Path Alias' },
      { id: 'menu', label: 'Menu' },
      { id: 'menu_link', label: 'Menu Link' },
      { id: 'workflow', label: 'Workflow' },
      { id: 'pipeline', label: 'Pipeline' },
    ]
    const groups = groupEntityTypes(all)
    const keys = groups.map((g) => g.key)
    expect(keys).not.toContain('other')
    const total = groups.reduce((sum, g) => sum + g.entityTypes.length, 0)
    expect(total).toBe(12)
  })
})

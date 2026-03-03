export interface EntityTypeInfo {
  id: string
  label: string
  keys?: Record<string, string>
}

interface NavGroupDefinition {
  key: string
  labelKey: string
  entityTypeIds: string[]
}

export interface ResolvedNavGroup {
  key: string
  labelKey: string
  entityTypes: EntityTypeInfo[]
}

const navGroupDefinitions: NavGroupDefinition[] = [
  { key: 'people', labelKey: 'nav_group_people', entityTypeIds: ['user'] },
  { key: 'content', labelKey: 'nav_group_content', entityTypeIds: ['node', 'node_type'] },
  { key: 'taxonomy', labelKey: 'nav_group_taxonomy', entityTypeIds: ['taxonomy_term', 'taxonomy_vocabulary'] },
  { key: 'media', labelKey: 'nav_group_media', entityTypeIds: ['media', 'media_type'] },
  { key: 'structure', labelKey: 'nav_group_structure', entityTypeIds: ['path_alias', 'menu', 'menu_link'] },
  { key: 'workflows', labelKey: 'nav_group_workflows', entityTypeIds: ['workflow'] },
  { key: 'ai', labelKey: 'nav_group_ai', entityTypeIds: ['pipeline'] },
]

export function groupEntityTypes(entityTypes: EntityTypeInfo[]): ResolvedNavGroup[] {
  const claimed = new Set<string>()
  const groups: ResolvedNavGroup[] = []

  for (const def of navGroupDefinitions) {
    const matched = def.entityTypeIds
      .map((id) => entityTypes.find((et) => et.id === id))
      .filter((et): et is EntityTypeInfo => et !== undefined)

    if (matched.length > 0) {
      groups.push({ key: def.key, labelKey: def.labelKey, entityTypes: matched })
      for (const et of matched) {
        claimed.add(et.id)
      }
    }
  }

  const unclaimed = entityTypes.filter((et) => !claimed.has(et.id))
  if (unclaimed.length > 0) {
    groups.push({ key: 'other', labelKey: 'nav_group_other', entityTypes: unclaimed })
  }

  return groups
}

import type { AdminSurfaceErrorMeta } from './adminSurface'

export type PageBuilderBlockDefinition = {
  id: string
  version: number
  label: string
  renderer: string
  config_schema: Record<string, unknown>
}

export type PageBuilderLayoutDefinition = {
  id: string
  version: number
  regions: string[]
  required_regions: string[]
  allowed_blocks: string[]
}

export type PageBuilderTemplateDefinition = {
  id: string
  version: number
  allowed_layouts: string[]
  allowed_blocks: string[]
}

export type PageBuilderDefinitions = {
  blocks: PageBuilderBlockDefinition[]
  layouts: PageBuilderLayoutDefinition[]
  templates: PageBuilderTemplateDefinition[]
}

export type PageBuilderBlock = {
  id: string
  type: string
  version: number
  config: Record<string, unknown>
}

export type PageBuilderSection = {
  id: string
  layout: { id: string, version: number }
  regions: Record<string, PageBuilderBlock[]>
}

export type PageBuilderDocument = {
  schema: 'waaseyaa.layout'
  version: number
  template: { id: string, version: number }
  sections: PageBuilderSection[]
}

export type PageBuilderDraft = {
  entity_id: string
  entity_revision_id: number
  document_fingerprint: string
  document: PageBuilderDocument
}

export type PageBuilderPreview = {
  entity_id: string
  revision_id: number
  expires_at: number
  signature: string
  preview_url: string
}

export type PageBuilderRevision = {
  revision_id: number
  created_at: string | null
  author_id: number | null
  log: string | null
  is_current: boolean
  is_latest: boolean
  document_fingerprint: string
  block_count: number
}

export type PageBuilderCommand =
  | { type: 'add_block', section_id: string, region_id: string, position: number, block: PageBuilderBlock }
  | { type: 'duplicate_block', source_block_id: string, duplicate_block_id: string }
  | { type: 'configure_block', block_id: string, config: Record<string, unknown> }
  | { type: 'move_block', block_id: string, destination_section_id: string, destination_region_id: string, position: number }
  | { type: 'remove_block', block_id: string }
  | { type: 'add_section', position: number, section: PageBuilderSection }
  | { type: 'duplicate_section', source_section_id: string, duplicate_section_id: string, duplicate_block_ids: Record<string, string> }
  | { type: 'move_section', section_id: string, position: number }
  | { type: 'remove_section', section_id: string }
  | { type: 'change_section_layout', section_id: string, layout_id: string, layout_version: number }

/**
 * The Admin Surface error envelope as the page-builder transport carries it.
 *
 * `code` and `meta` are the closed save-advisory contract (#2473/#2474): the
 * host projects only the allowlisted `meta.save_advisories` shape, so this type
 * stays a closed allowlist and must never widen to an index signature.
 */
export type PageBuilderSurfaceError = {
  status: number
  title: string
  detail?: string
  code?: string
  meta?: AdminSurfaceErrorMeta
}

export type PageBuilderSurfaceResult<T> = {
  ok: boolean
  data?: T
  error?: PageBuilderSurfaceError
}

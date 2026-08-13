import type {
  PageBuilderCommand,
  PageBuilderDefinitions,
  PageBuilderDraft,
  PageBuilderPreview,
  PageBuilderSurfaceResult,
} from '../contracts/pageBuilder'
import { adminSurfaceFetchUrl } from './adminSurfaceRoutes'

export type PageBuilderFetch = <T>(url: string, options?: Record<string, unknown>) => Promise<T>

export class PageBuilderClient {
  constructor(
    private readonly appBase: string,
    private readonly fetch: PageBuilderFetch,
  ) {}

  definitions(surface: string): Promise<PageBuilderSurfaceResult<{ definitions: PageBuilderDefinitions }>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.definitions', { surface }))
  }

  draft(surface: string, id: string): Promise<PageBuilderSurfaceResult<PageBuilderDraft>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.draft', { surface, id }), {
      cache: 'no-store',
    })
  }

  command(
    surface: string,
    id: string,
    draft: PageBuilderDraft,
    command: PageBuilderCommand,
    idempotencyKey: string,
  ): Promise<PageBuilderSurfaceResult<PageBuilderDraft>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.command', { surface, id }), {
      method: 'POST',
      body: {
        expected_entity_revision_id: draft.entity_revision_id,
        expected_document_fingerprint: draft.document_fingerprint,
        idempotency_key: idempotencyKey,
        command,
      },
    })
  }

  preview(surface: string, id: string, revisionId: number): Promise<PageBuilderSurfaceResult<PageBuilderPreview>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.preview', { surface, id }), {
      method: 'POST',
      body: { expected_entity_revision_id: revisionId },
    })
  }
}

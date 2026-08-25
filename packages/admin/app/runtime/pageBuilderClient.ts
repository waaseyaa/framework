import type {
  PageBuilderCommand,
  PageBuilderDefinitions,
  PageBuilderDraft,
  PageBuilderPreview,
  PageBuilderRevision,
  PageBuilderSurfaceResult,
} from '../contracts/pageBuilder'
import { adminSurfaceFetchUrl } from './adminSurfaceRoutes'

export type PageBuilderFetch = <T>(url: string, options?: Record<string, unknown>) => Promise<T>

/**
 * Resolve the body even when the status line is a refusal (#2409).
 *
 * The page-builder endpoints promote a typed refusal onto the HTTP status line,
 * but every refusal this client's callers act on is read out of the *body*:
 * `usePageBuilder` branches on `error.status === 409` for the conflict prompt,
 * on `428` for the save-advisory review, and on `501` for an unsupported
 * acknowledgement. `useApi().apiFetch` is plain `$fetch`, which throws on a
 * non-2xx status, so without this every one of those refusals would collapse
 * into the generic catch and the review flow would be lost.
 *
 * `plugins/admin.ts` and `useAuth` already pass the same option for the same
 * reason on the five `admin_surface.*` routes.
 */
const RESOLVE_REFUSAL_BODY = { ignoreResponseError: true } as const

export class PageBuilderClient {
  constructor(
    private readonly appBase: string,
    private readonly fetch: PageBuilderFetch,
  ) {}

  definitions(surface: string): Promise<PageBuilderSurfaceResult<{ definitions: PageBuilderDefinitions }>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.definitions', { surface }), {
      ...RESOLVE_REFUSAL_BODY,
    })
  }

  draft(surface: string, id: string): Promise<PageBuilderSurfaceResult<PageBuilderDraft>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.draft', { surface, id }), {
      ...RESOLVE_REFUSAL_BODY,
      cache: 'no-store',
    })
  }

  /**
   * Apply one edit command.
   *
   * `saveAdvisoryAcknowledgements` carries receipts the server minted for this
   * exact candidate. The key is omitted entirely when there are none, so an
   * ordinary save sends the byte-identical body it always sent and the host's
   * strict key contract is unchanged.
   */
  command(
    surface: string,
    id: string,
    draft: PageBuilderDraft,
    command: PageBuilderCommand,
    idempotencyKey: string,
    saveAdvisoryAcknowledgements: string[] = [],
  ): Promise<PageBuilderSurfaceResult<PageBuilderDraft>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.command', { surface, id }), {
      ...RESOLVE_REFUSAL_BODY,
      method: 'POST',
      body: {
        expected_entity_revision_id: draft.entity_revision_id,
        expected_document_fingerprint: draft.document_fingerprint,
        idempotency_key: idempotencyKey,
        command,
        ...(saveAdvisoryAcknowledgements.length > 0
          ? { save_advisory_acknowledgements: saveAdvisoryAcknowledgements }
          : {}),
      },
    })
  }

  preview(surface: string, id: string, revisionId: number): Promise<PageBuilderSurfaceResult<PageBuilderPreview>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.preview', { surface, id }), {
      ...RESOLVE_REFUSAL_BODY,
      method: 'POST',
      body: { expected_entity_revision_id: revisionId },
    })
  }

  history(surface: string, id: string): Promise<PageBuilderSurfaceResult<{ revisions: PageBuilderRevision[] }>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.history', { surface, id }), {
      ...RESOLVE_REFUSAL_BODY,
      cache: 'no-store',
    })
  }

  revision(surface: string, id: string, revisionId: number): Promise<PageBuilderSurfaceResult<PageBuilderDraft>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.revision', {
      surface,
      id,
      revision: String(revisionId),
    }), { ...RESOLVE_REFUSAL_BODY, cache: 'no-store' })
  }

  restore(surface: string, id: string, targetRevisionId: number, expectedCurrentRevisionId: number, idempotencyKey: string): Promise<PageBuilderSurfaceResult<PageBuilderDraft>> {
    return this.fetch(adminSurfaceFetchUrl(this.appBase, 'admin_surface.page_builder.restore', { surface, id }), {
      ...RESOLVE_REFUSAL_BODY,
      method: 'POST',
      body: {
        target_revision_id: targetRevisionId,
        expected_current_revision_id: expectedCurrentRevisionId,
        idempotency_key: idempotencyKey,
      },
    })
  }
}

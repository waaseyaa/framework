<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Draft;

/**
 * Supplies the canonical encoded layout for an entity whose stored document is
 * absent.
 *
 * Content migrated from another CMS has no page-builder document yet, and
 * {@see LayoutDraftSnapshot} cannot legally represent that state, so a gateway
 * without this seam can only refuse such an entity. A gateway composed with a
 * provider projects the returned document instead — a read projection, never a
 * write: the entity keeps no stored document until an editor saves one.
 *
 * The application owns the document. It must be a non-empty canonical encoded
 * layout; the composing gateway refuses an empty return rather than producing
 * an illegal snapshot.
 *
 * @api
 */
interface InitialLayoutDocumentProviderInterface
{
    public function initialEncodedLayout(string $entityId): string;
}

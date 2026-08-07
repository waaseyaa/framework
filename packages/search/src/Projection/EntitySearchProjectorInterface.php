<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Projection;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Search\SearchIndexableInterface;

/**
 * Projects a normal entity (one that does not itself implement
 * {@see SearchIndexableInterface}) into an indexable search document.
 *
 * This is the package-layer-safe extension point that lets lower-layer
 * content entities (node, taxonomy, …) become searchable without depending
 * upward on waaseyaa/search: the projector lives on the search side and keys
 * off the generic {@see EntityInterface} contract only.
 *
 * One projection contract, four call sites: a full `search:reindex`, the
 * save/delete/revision-pointer lifecycle subscriber, and query-time candidate
 * re-projection all resolve entities through the same
 * {@see EntitySearchProjectionRegistry}, so there is no index/query semantic
 * split.
 *
 * Contract rules:
 * - `project()` runs in two field-read contexts: WITHOUT an account scope at
 *   index time (only Public-classified fields are readable) and INSIDE the
 *   acting principal's field-read scope at query time. Implementations must
 *   read field values through the guarded {@see EntityInterface::get()}
 *   accessor and either catch a per-field denial and omit that field's text
 *   (graceful: the entity stays findable by its readable fields) or let the
 *   denial propagate (strict: the whole candidate is dropped fail-closed by
 *   the query-time resolver). Never bypass the accessor.
 * - The returned document's id MUST be the canonical entity form
 *   {@see EntitySearchDocumentId::fromEntity()} ("type:id"); query-time
 *   resolution rejects any other shape fail-closed.
 * - Field text is untrusted CMS data. Normalize markup to inert searchable
 *   text (see {@see SearchTextNormalizer}); never emit raw markup.
 * - `supports()` must be cheap and side-effect free: it is also consulted on
 *   the deletion path, where no projection is possible any more.
 *
 * @api
 */
interface EntitySearchProjectorInterface
{
    public function supports(EntityInterface $entity): bool;

    /**
     * Project the entity, or return null to keep it out of the index.
     *
     * A supporting projector's null is final: the registry does not fall
     * through to later projectors, so an application projector can both
     * override the built-in projection and deliberately exclude content.
     */
    public function project(EntityInterface $entity): ?SearchIndexableInterface;
}

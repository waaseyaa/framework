<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Projection;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;
use Waaseyaa\Search\Document\SearchDocument;
use Waaseyaa\Search\SearchIndexableInterface;

/**
 * Default projection for the framework's `node` content entity type.
 *
 * Keys off the entity type id through the generic {@see EntityInterface}
 * contract only — no `Waaseyaa\Node` import, no composer edge — so the
 * search package stays layer- and metapackage-clean while ordinary CMS
 * content becomes searchable.
 *
 * Safe by construction: every field value is read through the guarded
 * accessor, so index-time projection (no account scope) can only see
 * Public-classified fields — a Protected or unclassified (Internal) body is
 * omitted, never indexed. A per-field denial drops that field's text and
 * keeps the node findable by its readable fields, mirroring
 * {@see \Waaseyaa\Api\ResourceSerializer}'s omit-on-deny convention. The
 * title comes from the entity label (label keys are structurally Public).
 *
 * The default canonical URL is the root-mounted public `slug` field
 * ("/{slug}", segments validated against a conservative pattern so untrusted
 * slugs cannot project off-origin or scheme-carrying URLs); sites with a
 * different URL scheme or extra searchable fields register their own
 * projector via {@see \Waaseyaa\Search\ProvidesEntitySearchProjectorsInterface},
 * which takes precedence over this default.
 *
 * @api
 */
final class NodeSearchProjector implements EntitySearchProjectorInterface
{
    /** @var list<string> */
    private readonly array $bodyFields;

    /** @param array<string> $bodyFields Guarded field names concatenated into the document body, in order. */
    public function __construct(
        private readonly string $entityTypeId = 'node',
        array $bodyFields = ['body'],
    ) {
        $this->bodyFields = array_values($bodyFields);
    }

    public function supports(EntityInterface $entity): bool
    {
        return $entity->getEntityTypeId() === $this->entityTypeId
            && EntitySearchDocumentId::fromEntity($entity) !== null;
    }

    public function project(EntityInterface $entity): ?SearchIndexableInterface
    {
        $documentId = EntitySearchDocumentId::fromEntity($entity);
        if ($documentId === null || $entity->getEntityTypeId() !== $this->entityTypeId) {
            return null;
        }

        $bodyParts = [];
        foreach ($this->bodyFields as $field) {
            $part = SearchTextNormalizer::normalize($this->guardedValue($entity, $field));
            if ($part !== '') {
                $bodyParts[] = $part;
            }
        }

        $metadata = [
            'entity_type' => $this->entityTypeId,
            'content_type' => $entity->bundle(),
            'url' => $this->canonicalUrl($entity),
        ];
        $createdAt = $this->createdAt($entity);
        if ($createdAt !== null) {
            $metadata['created_at'] = $createdAt;
        }

        return new SearchDocument(
            id: $documentId,
            title: SearchTextNormalizer::normalize($this->guardedLabel($entity)),
            body: implode(' ', $bodyParts),
            metadata: $metadata,
        );
    }

    private function guardedLabel(EntityInterface $entity): string
    {
        try {
            return $entity->label();
        } catch (FieldReadDenied|MissingFieldReadContext) {
            return '';
        }
    }

    private function guardedValue(EntityInterface $entity, string $field): mixed
    {
        try {
            return $entity->get($field);
        } catch (FieldReadDenied|MissingFieldReadContext) {
            return null;
        }
    }

    private function canonicalUrl(EntityInterface $entity): string
    {
        $slug = $this->guardedValue($entity, 'slug');
        if (!is_string($slug) || $slug === '') {
            return '';
        }
        // Root-relative segments only: no leading/empty/dot-led segments, no
        // scheme separators — an untrusted slug must not become "//host" or
        // "scheme:…" in a rendered result link.
        if (preg_match('#^[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*)*$#i', $slug) !== 1) {
            return '';
        }

        return '/' . $slug;
    }

    private function createdAt(EntityInterface $entity): ?string
    {
        $created = $this->guardedValue($entity, 'created');
        if ($created instanceof \DateTimeInterface) {
            return $created->format(\DateTimeInterface::ATOM);
        }
        if (is_int($created) && $created > 0) {
            return gmdate(\DateTimeInterface::ATOM, $created);
        }

        return null;
    }
}

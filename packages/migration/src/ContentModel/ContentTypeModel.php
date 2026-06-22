<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\ContentModel;

use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * One content type in a derived {@see ContentModel}.
 *
 * Source-agnostic: a source reader (WordPress, Drupal, a CSV, anything) infers
 * these from its own source and the framework registers them uniformly. A
 * content type maps to an entity type + bundle (e.g. node/page) and carries the
 * field definitions that are DISTINCT to this type (the ones the source uses
 * that are not already declared as base fields on the entity class), plus a note
 * of the shared base fields it reuses (author, dates, title, slug, ...), for the
 * human-readable model description.
 *
 * UNWIRED / EXPERIMENTAL (audit C-5): part of the unwired ContentModel
 * scaffolding; no production path constructs or consumes it. @api retained only
 * for the dead-code gate.
 *
 * @internal Unsupported scaffolding pending wiring. See docs/specs/migration-platform.md.
 * @api
 */
final readonly class ContentTypeModel
{
    /**
     * @param string $entityTypeId Destination entity type (e.g. 'node').
     * @param string $bundle Destination bundle (e.g. 'page', 'news').
     * @param string $label Human-readable content-type label.
     * @param string $description Optional prose describing the type.
     * @param list<FieldDefinitionInterface> $fields Type-distinct declared fields
     *   (each must carry targetEntityTypeId === $entityTypeId).
     * @param list<string> $sharedFields Names of already-declared base fields this
     *   type reuses (informational, for the model description).
     * @param list<string> $notes Normalization notes for this type.
     */
    public function __construct(
        public string $entityTypeId,
        public string $bundle,
        public string $label,
        public string $description = '',
        public array $fields = [],
        public array $sharedFields = [],
        public array $notes = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Attribute;

/**
 * Declares the machine entity type id for a content entity class (e.g. 'todo', 'node').
 * Used by SSR app-controller argument binding to map PHP types to storage.
 *
 * @param string $id          Machine id of the entity type. Required.
 * @param string $label       Human-readable label. When empty, callers fall back to
 *                            an id-derived label (typically `ucfirst($id)`) at
 *                            consumption time. Optional, defaults to the empty
 *                            string for backwards compatibility with existing
 *                            single-argument call sites.
 * @param string $description Optional human prose describing the entity type. May
 *                            be surfaced in admin UIs and generated documentation.
 * @param bool   $api         Whether generic JSON:API routes are deliberately exposed.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class ContentEntityType
{
    public function __construct(
        public string $id,
        public string $label = '',
        public string $description = '',
        public bool $api = false,
    ) {}
}

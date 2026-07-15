<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Attribute;

/**
 * @deprecated Use #[Waaseyaa\Entity\Attribute\ContentEntityType] on content
 * entities. Compiled discovery now consumes that canonical metadata directly.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsEntityType
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {}
}

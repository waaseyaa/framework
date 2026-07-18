<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\FieldReadLevel;

/** @api */
final readonly class FieldReadMetadata
{
    public function __construct(
        public ?FieldReadLevel $level,
        public FieldReadMetadataSource $source,
    ) {}

    /**
     * Dormant-stage interpretation. Activation replaces this compatibility
     * fallback with fail-closed Internal after preflight completeness.
     */
    public function compatibilityLevel(): FieldReadLevel
    {
        return $this->level ?? FieldReadLevel::Public;
    }
}

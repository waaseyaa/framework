<?php

declare(strict_types=1);

namespace Aurora\I18n;

/**
 * Value object representing a language in the system.
 *
 * Immutable. Direction is 'ltr' (left-to-right) or 'rtl' (right-to-left).
 */
final readonly class Language
{
    public function __construct(
        public string $id,
        public string $label,
        public string $direction = 'ltr',
        public int $weight = 0,
        public bool $isDefault = false,
    ) {}
}

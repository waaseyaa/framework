<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\PageComposition;

/**
 * One formatter-produced, access-filtered entity field fragment.
 *
 * Raw values are deliberately absent so application page composers cannot
 * bypass the framework's formatter/sanitizer boundary.
 *
 * @api
 */
final readonly class EntityPageField
{
    public function __construct(
        public string $name,
        public string $type,
        public string $formatted,
    ) {}
}

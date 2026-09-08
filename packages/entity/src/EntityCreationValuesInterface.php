<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Prepares semantic values for one newly created entity before sealed construction.
 *
 * Storage hydration never invokes this contract. Implementations may derive
 * integrity bindings from caller-owned semantic fields, but must not perform
 * persistence or external side effects.
 *
 * @api
 */
interface EntityCreationValuesInterface
{
    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function prepareCreationValues(array $values): array;
}

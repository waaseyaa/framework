<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Closed view over the exact non-internal authorization inputs compiled for a policy.
 *
 * @api
 */
interface PolicySubjectViewInterface
{
    /** @return list<string> */
    public function fields(): array;

    public function get(string $fieldName): mixed;
}

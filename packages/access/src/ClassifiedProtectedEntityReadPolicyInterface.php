<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Protected entity policy with an exact private classification projection.
 *
 * Public fields may be inputs without becoming protected output fields.
 *
 * @internal
 */
interface ClassifiedProtectedEntityReadPolicyInterface extends ProtectedEntityReadPolicyInterface
{
    /** @return list<string> Exact fields this policy may inspect. */
    public function classificationInputs(): array;
}

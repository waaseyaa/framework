<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Protected entity policy that declares its complete immutable subject shape.
 *
 * @internal Framework policies opt in only after every authorization input is
 * reviewed. Policies without this contract retain full-entity evaluation.
 */
interface ProjectedProtectedEntityReadPolicyInterface extends ProtectedEntityReadPolicyInterface
{
    /** @return list<string> Exact authorization-input field names. */
    public function authorizationInputs(): array;

}

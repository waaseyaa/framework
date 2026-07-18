<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Additive companion on a discovered legacy policy that exposes its V2 read policies.
 *
 * @api
 */
interface ProtectedReadPolicyProviderInterface
{
    public function protectedEntityReadPolicy(): ?ProtectedEntityReadPolicyInterface;

    public function protectedFieldReadPolicy(): ?ProtectedFieldReadPolicyInterface;
}

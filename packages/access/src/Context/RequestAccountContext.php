<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Context;

use Waaseyaa\Access\AccountInterface;

/**
 * Default mutable {@see AccountContextInterface} implementation.
 *
 * A deliberately dumb holder: it stores exactly what it is given and returns
 * `null` when unset. No coercion, no stacking, no restore helpers — the
 * set/restore discipline lives at the writer sites (research D1). The kernel
 * constructs exactly one instance per process and exposes it everywhere
 * downstream consumers resolve the context.
 */
final class RequestAccountContext implements AccountContextInterface
{
    private ?AccountInterface $account = null;

    public function current(): ?AccountInterface
    {
        return $this->account;
    }

    public function set(?AccountInterface $account): void
    {
        $this->account = $account;
    }
}

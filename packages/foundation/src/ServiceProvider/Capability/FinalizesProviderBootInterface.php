<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * Kernel-owned after-all-providers boot boundary.
 *
 * Finalizers run in provider order only after every provider's ordinary
 * boot method has returned successfully. Implementations must finalize
 * in-memory authority state only; runtime persistence and repair do not
 * belong at this boundary.
 *
 * @api
 */
interface FinalizesProviderBootInterface
{
    public function finalizeProviderBoot(): void;
}

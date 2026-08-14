<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

interface RequiresCapabilitiesInterface
{
    /** @return iterable<CapabilityRequirement> */
    public function capabilityRequirements(): iterable;
}

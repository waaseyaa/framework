<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

interface ProvidesCapabilitiesInterface
{
    /** @return iterable<CapabilityDeclaration> */
    public function capabilityDeclarations(): iterable;
}

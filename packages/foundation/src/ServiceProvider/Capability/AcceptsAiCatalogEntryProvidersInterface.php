<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/** @api */
interface AcceptsAiCatalogEntryProvidersInterface
{
    /** @param list<object> $providers Kernel-discovered public artifact contributors. */
    public function withAiCatalogEntryProviders(array $providers): void;
}

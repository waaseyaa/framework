<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/** @api */
interface AcceptsApiCatalogEntryProvidersInterface
{
    /** @param list<object> $providers Kernel-discovered catalog contributors. */
    public function withApiCatalogEntryProviders(array $providers): void;
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;

/** @api */
interface ProvidesApiCatalogEntriesInterface
{
    /** @return list<ApiCatalogEntry> */
    public function apiCatalogEntries(): array;
}

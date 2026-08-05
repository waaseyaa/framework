<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;

/** @api */
interface ProvidesAiCatalogEntriesInterface
{
    /** @return list<AiCatalogEntry> */
    public function aiCatalogEntries(): array;
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** @internal Storage schema metadata consumed by the coordinated SQL schema plan. */
interface EntityTypeStorageUniqueKeyDefinitionInterface
{
    /** @return list<array{name: string, fields: non-empty-list<string>}> */
    public function getStorageUniqueKeys(): array;
}

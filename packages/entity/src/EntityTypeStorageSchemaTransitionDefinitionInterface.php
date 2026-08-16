<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** @internal Domain transition metadata consumed by coordinated entity schema sync. */
interface EntityTypeStorageSchemaTransitionDefinitionInterface
{
    /** @return list<class-string> */
    public function getStorageSchemaTransitions(): array;
}

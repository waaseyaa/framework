<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Optional mutation invariant metadata for entity types. */
interface MutationInvariantEntityTypeInterface extends EntityTypeInterface
{
    /** Class, interface, or service ID required to authorize mutations, if any. */
    public function getRequiredMutationInvariant(): ?string;
}

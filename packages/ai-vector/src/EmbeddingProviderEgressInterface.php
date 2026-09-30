<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

/** Classifies whether an embedding provider may transmit source text off-host. */
interface EmbeddingProviderEgressInterface
{
    public function transmitsOffHost(): bool;
}

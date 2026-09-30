<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

/** @api Explicit one-attempt, two-second network budget for HTTP lifecycle indexing. */
interface EmbeddingSaveProviderInterface extends EmbeddingProviderInterface
{
    /** @return list<float> */
    public function embedForSave(string $text): array;
}

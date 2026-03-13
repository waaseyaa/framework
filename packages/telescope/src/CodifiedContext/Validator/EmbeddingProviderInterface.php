<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

interface EmbeddingProviderInterface
{
    /** @return float[] */
    public function embed(string $text): array;

    /**
     * @param string[] $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array;

    /**
     * @param float[] $a
     * @param float[] $b
     */
    public function cosineSimilarity(array $a, array $b): float;
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class OpenAIEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'text-embedding-3-small',
    ) {}

    /** @return float[] */
    public function embed(string $text): array
    {
        throw new \RuntimeException('OpenAI embedding provider requires network access.');
    }

    /**
     * @param string[] $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array
    {
        throw new \RuntimeException('OpenAI embedding provider requires network access.');
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        $denom = sqrt($normA) * sqrt($normB);
        if ($denom < 1e-10) {
            return 0.0;
        }

        return $dot / $denom;
    }
}

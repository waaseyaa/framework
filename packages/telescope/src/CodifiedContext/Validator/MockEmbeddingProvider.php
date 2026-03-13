<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class MockEmbeddingProvider implements EmbeddingProviderInterface
{
    /** @param float[] $fixedEmbeddings */
    public function __construct(
        private readonly array $fixedEmbeddings = [],
        private readonly int $dimensions = 8,
    ) {}

    /** @return float[] */
    public function embed(string $text): array
    {
        if (isset($this->fixedEmbeddings[$text])) {
            return $this->fixedEmbeddings[$text];
        }

        return $this->hashEmbed($text);
    }

    /**
     * @param string[] $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array
    {
        return array_map(fn(string $t) => $this->embed($t), $texts);
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

    /** @return float[] */
    private function hashEmbed(string $text): array
    {
        $hash = md5($text);
        $floats = [];

        for ($i = 0; $i < $this->dimensions; $i++) {
            $hex = substr($hash, $i * 2, 2);
            $floats[] = hexdec($hex) / 255.0;
        }

        // Normalize to unit vector
        $norm = sqrt(array_sum(array_map(fn(float $v) => $v ** 2, $floats)));
        if ($norm < 1e-10) {
            return array_fill(0, $this->dimensions, 0.0);
        }

        return array_map(fn(float $v) => $v / $norm, $floats);
    }
}

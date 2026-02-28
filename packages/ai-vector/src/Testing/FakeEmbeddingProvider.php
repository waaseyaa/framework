<?php

declare(strict_types=1);

namespace Aurora\AI\Vector\Testing;

use Aurora\AI\Vector\EmbeddingInterface;

/**
 * Deterministic embedding provider for testing.
 *
 * Generates embeddings by hashing the input text and using the hash bytes
 * to produce float values. The resulting vectors are normalized to unit
 * magnitude. This ensures the same text always produces the same vector.
 */
final class FakeEmbeddingProvider implements EmbeddingInterface
{
    public function __construct(
        private readonly int $dimensions = 128,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function embed(string $text): array
    {
        return $this->generateDeterministicVector($text);
    }

    /**
     * {@inheritdoc}
     */
    public function embedBatch(array $texts): array
    {
        return array_map($this->embed(...), $texts);
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Generate a deterministic normalized vector from text.
     *
     * Uses SHA-256 hash extended via HMAC iterations to fill the required
     * dimensions, then normalizes the resulting vector to unit magnitude.
     *
     * @return float[]
     */
    private function generateDeterministicVector(string $text): array
    {
        $vector = [];
        $iteration = 0;

        while (count($vector) < $this->dimensions) {
            // Use HMAC with iteration counter to generate enough bytes.
            $hash = hash_hmac('sha256', $text, (string) $iteration, true);
            $bytes = unpack('C*', $hash);

            foreach ($bytes as $byte) {
                if (count($vector) >= $this->dimensions) {
                    break;
                }
                // Map byte (0-255) to float (-1.0 to 1.0).
                $vector[] = ($byte / 127.5) - 1.0;
            }

            $iteration++;
        }

        return $this->normalize($vector);
    }

    /**
     * Normalize a vector to unit magnitude.
     *
     * @param float[] $vector
     * @return float[]
     */
    private function normalize(array $vector): array
    {
        $magnitude = 0.0;
        foreach ($vector as $value) {
            $magnitude += $value * $value;
        }
        $magnitude = sqrt($magnitude);

        if ($magnitude == 0.0) {
            return $vector;
        }

        return array_map(
            static fn(float $v): float => $v / $magnitude,
            $vector,
        );
    }
}

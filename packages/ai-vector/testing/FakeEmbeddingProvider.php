<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Testing;

use Waaseyaa\AI\Vector\EmbeddingInterface;
use Waaseyaa\AI\Vector\EmbeddingProviderEgressInterface;

/**
 * Deterministic embedding provider for tests and local fixtures.
 *
 * @internal
 */
final class FakeEmbeddingProvider implements EmbeddingInterface, EmbeddingProviderEgressInterface
{
    public function __construct(
        private readonly int $dimensions = 128,
    ) {}

    public function embed(string $text): array
    {
        return $this->generateDeterministicVector($text);
    }

    public function embedBatch(array $texts): array
    {
        return array_map($this->embed(...), $texts);
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function transmitsOffHost(): bool
    {
        return false;
    }

    /** @return float[] */
    private function generateDeterministicVector(string $text): array
    {
        $vector = [];
        $iteration = 0;

        while (count($vector) < $this->dimensions) {
            $bytes = unpack('C*', hash_hmac('sha256', $text, (string) $iteration, true));
            foreach ($bytes as $byte) {
                if (count($vector) >= $this->dimensions) {
                    break;
                }
                $vector[] = ($byte / 127.5) - 1.0;
            }
            $iteration++;
        }

        return $this->normalize($vector);
    }

    /**
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

        return array_map(static fn(float $value): float => $value / $magnitude, $vector);
    }
}

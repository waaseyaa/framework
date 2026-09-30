<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

/** @internal Numeric invariants of the canonical storage contract. */
final class VectorMath
{
    /** @param array<mixed> $vector */
    public static function validate(array $vector): void
    {
        if ($vector === [] || !array_is_list($vector)) {
            throw new \InvalidArgumentException('Embedding vectors must be nonempty numeric lists.');
        }
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException('Embedding vector components must be finite numbers.');
            }
        }
    }

    public static function identity(string $entityType, ?string $id = null): void
    {
        if (trim($entityType) === '' || ($id !== null && $id === '')) {
            throw new \InvalidArgumentException('Embedding entity type and ID must be nonempty.');
        }
    }

    /**
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    public static function cosine(array $a, array $b): float
    {
        // Scale separately before multiplication to avoid finite input overflow
        // and underflow. Both inputs have already passed validation.
        $scaleA = max(array_map(static fn(float|int $v): float => abs((float) $v), $a));
        $scaleB = max(array_map(static fn(float|int $v): float => abs((float) $v), $b));
        if ($scaleA === 0.0 || $scaleB === 0.0) {
            return 0.0;
        }
        $dot = $normA = $normB = 0.0;
        foreach ($a as $i => $value) {
            $x = $value / $scaleA;
            $y = $b[$i] / $scaleB;
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }
        return max(-1.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB))));
    }
}

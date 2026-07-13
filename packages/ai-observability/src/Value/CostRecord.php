<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Value;

/**
 * @api
 */
final readonly class CostRecord
{
    public function __construct(
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cachedTokens,
        public float $costUsd,
    ) {}
}

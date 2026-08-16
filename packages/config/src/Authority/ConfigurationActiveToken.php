<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

/** Content identity plus ordered activation identity. @api */
final readonly class ConfigurationActiveToken
{
    public function __construct(
        public string $generationId,
        public int $activationSequence,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $generationId) !== 1 || $activationSequence < 1) {
            throw new \InvalidArgumentException('Configuration active tokens require a SHA-256 generation and positive sequence.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;

/** Configuration-backed process epoch captured at kernel construction. */
final class ConfigurationRuntimeEpoch implements RuntimeEpochInterface
{
    public function __construct(
        private readonly ConfigurationActivatorInterface $activator,
        private readonly ConfigurationActiveToken $observedToken,
    ) {}

    public function hasChanged(): bool
    {
        $current = $this->activator->currentToken();

        return $current === null
            || $current->activationSequence !== $this->observedToken->activationSequence
            || !hash_equals($current->generationId, $this->observedToken->generationId);
    }

    public function fingerprint(): string
    {
        return 'configuration:' . hash('sha256', json_encode([
            $this->observedToken->generationId,
            $this->observedToken->activationSequence,
        ], JSON_THROW_ON_ERROR));
    }
}

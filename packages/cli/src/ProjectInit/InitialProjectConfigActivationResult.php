<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;

final readonly class InitialProjectConfigActivationResult
{
    public function __construct(
        public string $status,
        public string $requestId,
        public ConfigurationActiveToken $token,
        public string $manifestHash,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'request_id' => $this->requestId,
            'generation_id' => $this->token->generationId,
            'activation_sequence' => $this->token->activationSequence,
            'manifest_hash' => $this->manifestHash,
        ];
    }
}

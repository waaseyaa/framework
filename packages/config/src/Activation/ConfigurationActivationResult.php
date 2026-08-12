<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;

final readonly class ConfigurationActivationResult
{
    public function __construct(
        public string $status,
        public ConfigurationActiveToken $token,
        public string $requestId,
        public string $planHash,
        public string $inputHash = '',
        public ?ConfigurationActiveToken $originalExpectedToken = null,
        public ?string $candidateId = null,
    ) {}

    /** Canonical post-commit evidence identity; contains no configuration values. */
    public function evidenceHash(): string
    {
        return hash('sha256', json_encode([
            'schema' => 'configuration-activation-evidence.v1',
            'request_id' => $this->requestId,
            'candidate_id' => $this->candidateId ?? $this->requestId,
            'input_hash' => $this->inputHash,
            'original_expected_token' => $this->originalExpectedToken === null ? null : [
                'generation_id' => $this->originalExpectedToken->generationId,
                'activation_sequence' => $this->originalExpectedToken->activationSequence,
            ],
            'plan_hash' => $this->planHash,
            'committed_token' => [
                'generation_id' => $this->token->generationId,
                'activation_sequence' => $this->token->activationSequence,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

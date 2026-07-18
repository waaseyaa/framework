<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Security;

/** Fixed worker-only AgentRun projection. @api */
final readonly class AgentRunWorkerFields
{
    public function __construct(
        public int $accountId,
        public ?string $agentDefinitionId,
        public string $bundleJson,
        public string $prompt,
        public ?string $response,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}
}

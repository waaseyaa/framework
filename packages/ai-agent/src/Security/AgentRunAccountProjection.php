<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Security;

/** Fixed account-authorized projection used by the AgentRun HTTP boundary. @api */
final readonly class AgentRunAccountProjection
{
    /** @param list<array<string, mixed>> $transcript */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $agentDefinitionId,
        public string $prompt,
        public ?string $response,
        public array $transcript,
        public bool $transcriptTruncated,
        public int $tokenUsageIn,
        public int $tokenUsageOut,
        public ?int $costCents,
        public int $toolCallCount,
        public string $destructiveApproval,
        public ?string $pendingApprovalCallId,
        public ?string $approvalExpiresAt,
        public string $queuedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}
}

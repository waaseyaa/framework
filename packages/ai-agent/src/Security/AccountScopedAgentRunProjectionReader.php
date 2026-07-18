<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Security;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\Entity\AgentRun;

/** Establishes an explicit immutable account context for the fixed HTTP view. @api */
final readonly class AccountScopedAgentRunProjectionReader implements AgentRunAccountProjectionReaderInterface
{
    public function __construct(private AccountFieldReadScopeInterface $scope) {}

    public function read(AgentRun $run, AccountInterface $account): AgentRunAccountProjection
    {
        $principal = $account instanceof AuthorizationPrincipalInterface
            ? $account
            : new AuthorizationPrincipal(
                accountId: $account->id(),
                authenticated: $account->isAuthenticated(),
                roles: $account->getRoles(),
                permissions: $account->hasPermission(AgentRunAccessPolicy::PERMISSION_BYPASS_OWNERSHIP)
                    ? [AgentRunAccessPolicy::PERMISSION_BYPASS_OWNERSHIP]
                    : [],
                claimsGeneration: hash('sha256', serialize([$account->id(), $account->getRoles()])),
            );

        return $this->scope->run($principal, static function () use ($run): AgentRunAccountProjection {
            $transcript = [];
            $truncated = false;
            $transcriptRaw = $run->get('transcript_json');
            if (is_string($transcriptRaw) && $transcriptRaw !== '') {
                try {
                    $decoded = json_decode($transcriptRaw, true, 64, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $transcript = array_values($decoded);
                    }
                } catch (\JsonException) {
                    $truncated = true;
                }
            }

            return new AgentRunAccountProjection(
                id: (string) $run->get('id'),
                status: $run->getStatus()->value,
                agentDefinitionId: is_string($run->get('agent_definition_id')) ? $run->get('agent_definition_id') : null,
                prompt: (string) ($run->get('prompt') ?? ''),
                response: is_string($run->get('response')) ? $run->get('response') : null,
                transcript: $transcript,
                transcriptTruncated: $truncated,
                tokenUsageIn: (int) ($run->get('token_usage_in') ?? 0),
                tokenUsageOut: (int) ($run->get('token_usage_out') ?? 0),
                costCents: is_int($run->get('cost_cents')) ? $run->get('cost_cents') : null,
                toolCallCount: (int) ($run->get('tool_call_count') ?? 0),
                destructiveApproval: $run->getDestructiveApproval()->value,
                pendingApprovalCallId: is_string($run->get('pending_approval_call_id')) ? $run->get('pending_approval_call_id') : null,
                approvalExpiresAt: self::nullableString($run->get('approval_expires_at')),
                queuedAt: (string) ($run->get('queued_at') ?? ''),
                startedAt: self::nullableString($run->get('started_at')),
                finishedAt: self::nullableString($run->get('finished_at')),
                errorCode: is_string($run->get('error_code')) ? $run->get('error_code') : null,
                errorMessage: is_string($run->get('error_message')) ? $run->get('error_message') : null,
            );
        });
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

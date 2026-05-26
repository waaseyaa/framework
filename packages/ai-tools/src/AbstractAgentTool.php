<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

/**
 * Convenience base for {@see AgentToolInterface} implementations.
 *
 * Provides:
 *  - A default {@see argumentsForAudit()} that redacts common credential keys.
 *  - A default {@see dryRun()} that returns a `dry_run_not_supported` error.
 *
 * Concrete tools SHOULD extend this class and implement {@see execute()}
 * and {@see inputSchema()}. Tools declaring `dryRunSupported: true` in
 * their `#[AsAgentTool]` attribute MUST override {@see dryRun()}.
 *
 * @api
 */
abstract class AbstractAgentTool implements AgentToolInterface
{
    /**
     * Argument keys redacted by the default audit transform.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = ['password', 'token', 'api_key', 'secret'];

    /**
     * {@inheritDoc}
     */
    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        return AgentToolResult::error(
            message: 'dry_run_not_supported',
            summary: 'This tool does not support dry-run; call execute() instead.',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function argumentsForAudit(array $arguments): array
    {
        $redacted = [];
        foreach ($arguments as $key => $value) {
            $keyLower = strtolower($key);
            if (in_array($keyLower, self::REDACTED_KEYS, true)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $redacted[$key] = $this->argumentsForAudit($value);
                continue;
            }
            $redacted[$key] = $value;
        }

        return $redacted;
    }

    /**
     * Enforce the tool's capability against the account in the supplied context.
     *
     * Returns a `forbidden` {@see AgentToolResult} when the account lacks
     * the capability; concrete tools can call this from {@see execute()}
     * to short-circuit cleanly.
     */
    protected function requireCapability(string $capability, AgentToolContext $context): ?AgentToolResult
    {
        if (!$context->account->hasPermission($capability)) {
            return AgentToolResult::error(
                message: sprintf('Account %s is not permitted to call %s', $context->account->id(), $capability),
                summary: 'forbidden',
            );
        }

        return null;
    }
}

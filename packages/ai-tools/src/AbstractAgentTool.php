<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;

/**
 * Convenience base for {@see AgentToolInterface} implementations.
 *
 * Provides:
 *  - A default {@see argumentsForAudit()} that redacts common credential keys.
 *  - A default {@see dryRun()} that returns a `dry_run_not_supported` error.
 *  - An optional {@see EntityAccessHandler} (set via {@see setAccessHandler()})
 *    and {@see requireEntityAccess()} / {@see requireCreateAccess()} guards, so
 *    entity tools can enforce the same per-entity AccessPolicy the rest of the
 *    framework uses, not just the coarse `tool.entity.*` capability. When no
 *    handler is set (the default), the guards are a no-op and only the
 *    capability check applies, preserving prior behavior.
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
     * Optional per-entity access policy gate. Null = capability check only
     * (the constructor signature of concrete tools is unchanged, so framework
     * discovery / container instantiation is unaffected). A consumer that wants
     * policy-gated tools constructs the tool and calls setAccessHandler().
     */
    private ?EntityAccessHandler $accessHandler = null;

    /**
     * Attach a policy gate so write/read operations consult the entity
     * AccessPolicy for (entity, operation, account) in addition to the
     * capability check.
     *
     * @api
     */
    public function setAccessHandler(?EntityAccessHandler $accessHandler): void
    {
        $this->accessHandler = $accessHandler;
    }

    /**
     * {@inheritDoc}
     */
    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
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
     * Enforce the tool's capability against the supplied account.
     *
     * Returns a `forbidden` {@see AgentToolResult} when the account lacks
     * the capability; concrete tools can call this from {@see execute()}
     * to short-circuit cleanly.
     */
    protected function requireCapability(string $capability, AccountInterface $account): ?AgentToolResult
    {
        if (!$account->hasPermission($capability)) {
            return AgentToolResult::error(
                message: sprintf('Account %s is not permitted to call %s', $account->id(), $capability),
                summary: 'forbidden',
            );
        }

        return null;
    }

    /**
     * Enforce the per-entity AccessPolicy for an operation on a loaded entity.
     * No-op when no access handler has been attached.
     */
    protected function requireEntityAccess(EntityInterface $entity, string $operation, AccountInterface $account): ?AgentToolResult
    {
        if ($this->accessHandler === null) {
            return null;
        }
        if (!$this->accessHandler->check($entity, $operation, $account)->isAllowed()) {
            return AgentToolResult::error(
                message: sprintf('Account %s is not permitted to %s %s/%s', $account->id(), $operation, $entity->getEntityTypeId(), (string) ($entity->id() ?? '')),
                summary: 'forbidden',
            );
        }

        return null;
    }

    /**
     * Enforce the per-entity AccessPolicy for creating an entity of a type.
     * No-op when no access handler has been attached.
     */
    protected function requireCreateAccess(string $entityTypeId, string $bundle, AccountInterface $account): ?AgentToolResult
    {
        if ($this->accessHandler === null) {
            return null;
        }
        if (!$this->accessHandler->checkCreateAccess($entityTypeId, $bundle, $account)->isAllowed()) {
            return AgentToolResult::error(
                message: sprintf('Account %s is not permitted to create %s', $account->id(), $entityTypeId),
                summary: 'forbidden',
            );
        }

        return null;
    }
}

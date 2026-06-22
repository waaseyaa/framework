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
 *  - An {@see EntityAccessHandler} (attached via {@see setAccessHandler()})
 *    and {@see requireEntityAccess()} / {@see requireCreateAccess()} guards, so
 *    entity tools enforce the same per-entity AccessPolicy the rest of the
 *    framework uses, not just the coarse `tool.entity.*` capability.
 *
 *    Access enforcement is **fail-closed once required** (C-12). Two states:
 *      - Capability-only (the bare-construction default, used by unit tests and
 *        any host that never wires per-entity policy): no handler, enforcement
 *        OFF — the guards allow, only the capability check applies. Unchanged
 *        prior behavior.
 *      - Enforced (the production default — {@see AttributeToolRegistry} stamps
 *        every tool it hydrates via {@see markAccessEnforced()} and attaches the
 *        kernel handler): the per-entity guards DENY when the handler is somehow
 *        absent, so a wiring gap can never silently degrade to "allow all".
 *    {@see setAccessHandler()} with a non-null handler turns enforcement ON
 *    implicitly; {@see markAccessEnforced()} turns it on without a handler so a
 *    failed resolve still fails closed.
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
     * Per-entity access policy gate. Null = no handler attached (the
     * constructor signature of concrete tools is unchanged, so framework
     * discovery / container instantiation is unaffected). The production
     * registry attaches the kernel handler at hydration; bare construction
     * (unit tests, capability-only hosts) leaves it null.
     */
    private ?EntityAccessHandler $accessHandler = null;

    /**
     * Whether per-entity access MUST be enforced. When true the guards
     * fail closed (deny) if {@see $accessHandler} is null, so a wiring gap
     * cannot silently downgrade to allow-all. Off by default to preserve
     * capability-only construction (unit tests, hosts with no entity policy).
     */
    private bool $accessEnforced = false;

    /**
     * Attach a policy gate so write/read operations consult the entity
     * AccessPolicy for (entity, operation, account) in addition to the
     * capability check.
     *
     * Passing a non-null handler also turns enforcement ON: from this point
     * the per-entity guards are authoritative, never a no-op.
     *
     * @api
     */
    public function setAccessHandler(?EntityAccessHandler $accessHandler): void
    {
        $this->accessHandler = $accessHandler;
        if ($accessHandler !== null) {
            $this->accessEnforced = true;
        }
    }

    /**
     * Require per-entity access enforcement even before (or without) a handler
     * is attached. The production registry calls this on every tool it
     * hydrates so that, should the kernel handler fail to resolve, the
     * per-entity guards fail closed (deny) rather than allow. Capability-only
     * construction never calls this and keeps the permissive default.
     *
     * @api
     */
    public function markAccessEnforced(): void
    {
        $this->accessEnforced = true;
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
        return $this->redactArguments($arguments);
    }

    /**
     * Recursively redact credential-named keys. Operates on arbitrarily-keyed
     * arrays because nested values may be lists (e.g. an entity.create
     * `values.blocks` / `tags`) — whose integer keys are never credential
     * names and, crucially, must not be passed to strtolower() under
     * strict_types (#1637).
     *
     * @param array<array-key, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function redactArguments(array $arguments): array
    {
        $redacted = [];
        foreach ($arguments as $key => $value) {
            $keyLower = is_string($key) ? strtolower($key) : '';
            if (in_array($keyLower, self::REDACTED_KEYS, true)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = $this->redactArguments($value);
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
     *
     * No-op only in capability-only mode (no handler AND enforcement not
     * required). When enforcement is required but no handler is present the
     * guard fails closed (deny) — a wiring gap must never read as "allow".
     */
    protected function requireEntityAccess(EntityInterface $entity, string $operation, AccountInterface $account): ?AgentToolResult
    {
        if ($this->accessHandler === null) {
            return $this->accessEnforced
                ? $this->accessUnenforceableError($account, $operation, $entity->getEntityTypeId())
                : null;
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
     * Predicate form of the per-entity 'view' gate, for filtering result sets.
     *
     * Returns true only in capability-only mode (no handler AND enforcement not
     * required — preserves prior behavior). When enforcement is required but no
     * handler is present this returns FALSE (fail closed): an enumeration path
     * must drop every candidate rather than leak a forbidden entity through a
     * wiring gap. With a handler it consults the entity AccessPolicy for
     * ('view', $account). List/search tools MUST filter each candidate through
     * this so a forbidden entity never leaks via enumeration (id/label/
     * existence) or substring match — the same per-entity gate
     * {@see EntityReadTool} applies to single reads.
     */
    protected function canViewEntity(EntityInterface $entity, AccountInterface $account): bool
    {
        if ($this->accessHandler === null) {
            return !$this->accessEnforced;
        }

        return $this->accessHandler->check($entity, 'view', $account)->isAllowed();
    }

    /**
     * Drop fields the account may not view, so a tool's serialized output never
     * leaks a `FieldAccessPolicyInterface`-forbidden field. Open-by-default:
     * fields with no field policy stay (only explicit Forbidden is dropped),
     * mirroring the JSON:API serializer. When no handler is present (capability-
     * only mode), values pass through unchanged — entity-level access has
     * already been enforced by the caller.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected function applyFieldAccessFilter(EntityInterface $entity, array $values, AccountInterface $account): array
    {
        if ($this->accessHandler === null || $values === []) {
            return $values;
        }

        $allowed = $this->accessHandler->filterFields($entity, array_keys($values), 'view', $account);

        return array_intersect_key($values, array_flip($allowed));
    }

    /**
     * Enforce the per-entity AccessPolicy for creating an entity of a type.
     *
     * No-op only in capability-only mode (no handler AND enforcement not
     * required). When enforcement is required but no handler is present the
     * guard fails closed (deny).
     */
    protected function requireCreateAccess(string $entityTypeId, string $bundle, AccountInterface $account): ?AgentToolResult
    {
        if ($this->accessHandler === null) {
            return $this->accessEnforced
                ? $this->accessUnenforceableError($account, 'create', $entityTypeId)
                : null;
        }
        if (!$this->accessHandler->checkCreateAccess($entityTypeId, $bundle, $account)->isAllowed()) {
            return AgentToolResult::error(
                message: sprintf('Account %s is not permitted to create %s', $account->id(), $entityTypeId),
                summary: 'forbidden',
            );
        }

        return null;
    }

    /**
     * Enforce per-field edit access for a write payload, mirroring the JSON:API
     * write path ({@see EntityAccessHandler::checkFieldAccess()} with the `edit`
     * operation; open-by-default — only an explicit Forbidden denies). Returns a
     * `forbidden` refusal for the first field the account may not edit (whole-
     * write rejection — the caller must run this BEFORE any `set()`), or null
     * when every submitted field is writable.
     *
     * No-op in capability-only mode (no handler attached): entity-level access
     * has already been enforced by the caller, and hosts/tests with no field
     * policy keep prior behavior — identical to {@see applyFieldAccessFilter()}.
     * Non-string keys are skipped (they are never field names).
     *
     * @param array<array-key, mixed> $values
     */
    protected function requireFieldEditAccess(EntityInterface $entity, array $values, AccountInterface $account): ?AgentToolResult
    {
        if ($this->accessHandler === null) {
            return null;
        }
        foreach ($values as $field => $_value) {
            if (!is_string($field)) {
                continue;
            }
            if ($this->accessHandler->checkFieldAccess($entity, $field, 'edit', $account)->isForbidden()) {
                return AgentToolResult::error(
                    message: sprintf('Account %s is not permitted to edit field %s on %s', $account->id(), $field, $entity->getEntityTypeId()),
                    summary: 'forbidden',
                );
            }
        }

        return null;
    }

    /**
     * Fail-closed denial: enforcement is required but no access handler is
     * available, so the per-entity gate cannot be evaluated and must deny. The
     * `forbidden` summary is identical to a policy denial, so callers (and the
     * agent) cannot distinguish "policy said no" from "could not check" — no
     * existence/identity oracle leaks through the difference.
     */
    private function accessUnenforceableError(AccountInterface $account, string $operation, string $entityTypeId): AgentToolResult
    {
        return AgentToolResult::error(
            message: sprintf('Account %s is not permitted to %s %s', $account->id(), $operation, $entityTypeId),
            summary: 'forbidden',
        );
    }
}

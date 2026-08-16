<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Audit\ConfigAuditChannel;
use Waaseyaa\Config\Audit\ConfigAuditEvent;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Exception\ConfigImportFailedException;

/**
 * Orchestrates `config:reset <entity-type>.<id>`: the inverse of one entry of
 * `config:import` — read a single sync-store entity, overwrite the active
 * store with it, and emit a structured audit-log event on the
 * {@see ConfigAuditChannel::CHANNEL} channel.
 *
 * Behaviour (FR-041, FR-042, FR-043, FR-053):
 *  1. Look up the sync-store file for `$ref` via
 *     {@see ConfigSyncRepository::get()}. If absent, return a STATUS_FAILED
 *     entry — the active store is left untouched and `$ref` is NOT logged
 *     as a reset (no destructive op occurred).
 *  2. Delegate the active-store write to a single call of
 *     {@see ConfigImportApplyHookInterface::apply()} — this reuses the
 *     WP04 transactional-write contract so reset and import share the same
 *     lifecycle-event surface.
 *  3. Emit a {@see ConfigAuditEvent} with `operation=OP_RESET`, the actor,
 *     the entity ref, an optional before/after digest, and a context
 *     payload that records the `--yes`-skip-confirmation flag plus
 *     hook-returned status.
 *  4. A {@see ConfigImportFailedException} from the hook is caught and
 *     surfaced as STATUS_FAILED; the audit event still fires so operators
 *     can correlate the failure with their retention window.
 *
 * **Confirmation contract:** confirmation is the calling
 * `ConfigResetCommand`'s responsibility — `ConfigResetter` itself never
 * prompts. The `$skipConfirmation` parameter is informational only; it
 * lands in the audit-event context so retention can distinguish
 * interactively-confirmed resets from `--yes`-bypassed ones (per the spec
 * §FR-042 and `data-model.md` §`ConfigResetter`).
 *
 * Stability scope (charter §5.5): the class FQCN, `reset()` signature,
 * parameter names, and the audit-logger seam (a duck-typed `?callable` to
 * preserve Layer-1 layer discipline, matching the {@see ConfigImporter}
 * pattern) are on stable surface for `waaseyaa/config` v1.x.
 *
 * **Audit logging contract:** the constructor accepts a duck-typed
 * `?callable` (signature
 * `function(string $level, string $message, ConfigAuditEvent $event): void`).
 * App wiring typically passes a closure that fans messages into the
 * {@see ConfigAuditChannel::CHANNEL} channel of
 * `Waaseyaa\Foundation\Log\LoggerInterface` — the config package stays
 * Layer 1 by avoiding the foundation import. `$level` is `'info'` for
 * successful resets and `'warning'` for failures, mirroring PSR-3.
 *
 * @api
 */
final class ConfigResetter
{
    /** @var (callable(string, string, ConfigAuditEvent): void)|null */
    private $auditLogger;

    /**
     * @param (callable(string, string, ConfigAuditEvent): void)|null $auditLogger
     *      Audit sink for `config.audit` events. `null` (default) disables
     *      audit logging — useful in unit-test wiring that asserts only
     *      the apply-hook side of the contract.
     */
    public function __construct(
        private readonly ConfigSyncRepository $repository,
        private readonly ConfigImportApplyHookInterface $applyHook,
        ?callable $auditLogger = null,
        private readonly ?ConfigurationActivatorInterface $activator = null,
        private readonly ?ConfigSyncFileSourceInterface $activeSource = null,
    ) {
        $this->auditLogger = $auditLogger;
    }

    /**
     * Reset one entity from the sync store.
     *
     * @param string      $ref              `<entity_type>.<entity_id>`.
     * @param string|null $actor            Actor identifier recorded on the audit event
     *                                       (CLI invoker / userId / `null` for system).
     * @param bool        $skipConfirmation Whether the caller skipped the operator prompt
     *                                       (e.g. `--yes`); recorded in the audit context only.
     */
    public function reset(
        string $ref,
        ?string $actor = null,
        bool $skipConfirmation = false,
        ?string $activationRequestId = null,
        ?ConfigurationActiveToken $expectedToken = null,
    ): ConfigImportEntryResult {
        $file = $this->repository->get($ref);
        if ($file === null) {
            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: sprintf('sync-store entity not found: %s', $ref),
            );
        }

        if ($this->activator !== null) {
            if ($activationRequestId === null || $activationRequestId === '' || $expectedToken === null) {
                return new ConfigImportEntryResult(
                    ref: $ref,
                    status: ConfigImportEntryResult::STATUS_FAILED,
                    reason: 'production config:reset requires activation request ID and expected active token',
                );
            }
            $active = null;
            foreach ($this->activeSource?->iterate() ?? [] as $activeFile) {
                if ($activeFile->ref() === $ref) {
                    $active = $activeFile;
                    break;
                }
            }
            try {
                $activation = $this->activator->activate(new ConfigurationActivationRequest(
                    $activationRequestId,
                    $expectedToken,
                    [$file],
                    expectedEntryHashes: $active === null ? [] : [$ref => $active->contentHash()],
                ));
            } catch (\Throwable $exception) {
                $wrapped = ConfigImportFailedException::applyFailed($ref, $exception->getMessage(), $exception);
                $this->emit('warning', sprintf('config:reset failed: %s — %s', $ref, $wrapped->getMessage()), $this->buildEvent(
                    $ref,
                    $actor,
                    $skipConfirmation,
                    'failed',
                    $wrapped->getMessage(),
                ));

                return new ConfigImportEntryResult($ref, ConfigImportEntryResult::STATUS_FAILED, $wrapped->getMessage());
            }
            $status = $active === null
                ? ConfigImportEntryResult::STATUS_CREATED
                : (hash_equals($active->contentHash(), $file->contentHash())
                    ? ConfigImportEntryResult::STATUS_UNCHANGED
                    : ConfigImportEntryResult::STATUS_UPDATED);
            $this->emit('info', sprintf('config:reset generation committed: %s (%s).', $ref, $status), $this->buildEvent(
                $ref,
                $actor,
                $skipConfirmation,
                $status,
                null,
            ));

            return new ConfigImportEntryResult($ref, $status);
        }

        try {
            $hookStatus = $this->applyHook->apply($file);
        } catch (ConfigImportFailedException $e) {
            $this->emit(
                level: 'warning',
                message: sprintf('config:reset failed: %s — %s', $ref, $e->getMessage()),
                event: $this->buildEvent($ref, $actor, $skipConfirmation, 'failed', $e->getMessage()),
            );

            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: $e->getMessage(),
            );
        } catch (\Throwable $e) {
            $wrapped = ConfigImportFailedException::applyFailed($ref, $e->getMessage(), $e);
            $this->emit(
                level: 'warning',
                message: sprintf('config:reset failed: %s — %s', $ref, $wrapped->getMessage()),
                event: $this->buildEvent($ref, $actor, $skipConfirmation, 'failed', $wrapped->getMessage()),
            );

            return new ConfigImportEntryResult(
                ref: $ref,
                status: ConfigImportEntryResult::STATUS_FAILED,
                reason: $wrapped->getMessage(),
            );
        }

        $this->emit(
            level: 'info',
            message: sprintf('config:reset applied: %s (%s).', $ref, $hookStatus),
            event: $this->buildEvent($ref, $actor, $skipConfirmation, $hookStatus, null),
        );

        return new ConfigImportEntryResult(ref: $ref, status: $hookStatus);
    }

    private function buildEvent(
        string $ref,
        ?string $actor,
        bool $skipConfirmation,
        string $hookStatus,
        ?string $reason,
    ): ConfigAuditEvent {
        $context = [
            'channel' => ConfigAuditChannel::CHANNEL,
            'skip_confirmation' => $skipConfirmation,
            'hook_status' => $hookStatus,
        ];
        if ($reason !== null) {
            $context['reason'] = $reason;
        }

        return new ConfigAuditEvent(
            operation: ConfigAuditEvent::OP_RESET,
            actor: $actor,
            entityRef: $ref,
            beforeAfterDigest: null,
            timestamp: time(),
            context: $context,
        );
    }

    private function emit(string $level, string $message, ConfigAuditEvent $event): void
    {
        if ($this->auditLogger === null) {
            return;
        }
        ($this->auditLogger)($level, $message, $event);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Account;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Resolve the {@see AccountInterface} that initiated an
 * {@see \Waaseyaa\AI\Agent\Entity\AgentRun}.
 *
 * The handler needs a live account object to pass to
 * {@see \Waaseyaa\AI\Agent\AgentExecutor::executeRun()} (which uses it
 * for tool-call access checks). When a run is enqueued the
 * `agent_run.account_id` is persisted; the worker reloads it from this
 * loader rather than touching the user package directly, keeping the
 * L5 → L1 boundary clean.
 *
 * Apps that need real users (roles, permissions, capabilities) wire
 * their own implementation; the default {@see StubInitiatorAccountLoader}
 * yields a minimal authenticated account suitable for tests, the
 * `NullLlmProvider` smoke path, and apps that delegate access checks
 * elsewhere.
 *
 * @api
 */
interface InitiatorAccountLoaderInterface
{
    /**
     * Load the initiator account by id. Implementations SHOULD return a
     * minimally authenticated account for unknown ids rather than `null`
     * so the worker can still record the failure path; throw only for
     * actual storage errors.
     */
    public function load(int|string $accountId): AuthorizationPrincipalInterface;
}

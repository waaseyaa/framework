<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\LocalOperator;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;

/**
 * An `AccountContextInterface` decorator that refuses to hold a
 * {@see LocalOperatorPrincipal} (ADR-022 D-6 R-4).
 *
 * R-4 says the principal "MUST NOT be installed into the kernel
 * `AccountContext`", and D-6 says every refusal "MUST be an explicit, loud
 * failure — a silent downgrade to anonymous is not an acceptable
 * implementation of any row". Without this decorator R-4 would be a docblock
 * warning: `AccountContextInterface` implementations are deliberately dumb
 * holders, `EntityRepository::resolveActor()` casts the ambient account's
 * `id()` to `int`, and `(int) 'local-operator:stdio'` is `0` — the
 * `AnonymousUser` id. That is exactly the *silent* downgrade R-4 exists to
 * forbid, and the mistake would surface only later, as a `revision_author` of
 * `0` on stored rows.
 *
 * The local stdio transport wraps whatever account context it resolves in one
 * of these. The principal is passed directly to the surfaces that consult it
 * (the access gate, `AbstractAgentTool::requireCapability()`), never installed
 * as the ambient acting account.
 *
 * **Both directions are guarded.** `set()` refuses the write, and `current()`
 * refuses the read — because blocking only the write leaves two live paths to
 * a prohibited read: wrapping a context that already holds the principal, and
 * mutation of the inner context through a second reference obtained before the
 * wrap. `current()` is also the side `EntityRepository::resolveActor()`
 * consumes, so it is the one that actually produces the uid-`0` attribution.
 *
 * The guard is transparent for every other account, including `null` — it is a
 * boundary, not a policy.
 *
 * @api
 */
final class LocalOperatorAccountContextGuard implements AccountContextInterface
{
    public function __construct(
        private readonly AccountContextInterface $inner,
    ) {}

    /**
     * Refuse on the READ side too.
     *
     * `current()` is the side `EntityRepository::resolveActor()` actually
     * consumes, so a guard that only refuses `set()` is a guard over the wrong
     * half. Two ordinary situations reach a prohibited read without ever
     * calling `set()` on this decorator:
     *
     *  1. the guard wraps a context that ALREADY holds the principal, and
     *  2. something mutates the inner context through a second reference held
     *     from before the wrap.
     *
     * In both, an unconditional pass-through hands the principal straight back
     * and the `(int)` cast turns it into uid `0`. Reading prohibited state is
     * refused as loudly as writing it.
     */
    public function current(): ?AccountInterface
    {
        $account = $this->inner->current();
        if ($account instanceof LocalOperatorPrincipal) {
            throw LocalOperatorRefusal::ambientAccountObserved();
        }

        return $account;
    }

    public function set(?AccountInterface $account): void
    {
        if ($account instanceof LocalOperatorPrincipal) {
            throw LocalOperatorRefusal::ambientAccountInstallation();
        }

        $this->inner->set($account);
    }
}

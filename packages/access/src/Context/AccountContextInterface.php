<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Context;

use Waaseyaa\Access\AccountInterface;

/**
 * Request-scoped holder for the acting account — the account in whose name
 * the current operation runs.
 *
 * Three-state model:
 *   - account N: an authenticated account (id N) is acting.
 *   - anonymous 0: an anonymous account (id 0, e.g. `AnonymousUser`) is
 *     acting — anonymous IS an actor; the holder performs no sentinel
 *     handling and stores it like any other account.
 *   - null: no acting context exists (CLI, queue workers, bootstrap).
 *
 * Scoping contract (writers own the discipline, the holder stays dumb):
 *   - HTTP requests are the outermost scope — `SessionMiddleware` overwrites
 *     unconditionally on every request, never restores.
 *   - Non-HTTP writers (MCP endpoint, agent executor) set the context for
 *     the duration of their run and restore the prior value in `finally`.
 *   - CLI/queue/bootstrap contexts read `null` unless something sets it.
 *
 * @api
 */
interface AccountContextInterface
{
    /**
     * The account in whose name the current operation runs, or null when no
     * acting context exists.
     */
    public function current(): ?AccountInterface;

    /**
     * Set (or clear with null) the acting account for the current
     * request/run scope.
     */
    public function set(?AccountInterface $account): void;
}

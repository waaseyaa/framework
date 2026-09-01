<?php

declare(strict_types=1);

namespace Waaseyaa\Database;

/**
 * Transaction whose completion callbacks follow the outermost managed commit.
 *
 * A callback registered on a nested transaction is promoted to its managed
 * parent when the savepoint is released. It runs only after the physical outer
 * commit and is discarded when a rollback removes that transaction frame.
 *
 * @api
 */
interface TransactionCompletionInterface extends TransactionInterface
{
    /** Register work that is valid only after the outermost managed commit. */
    public function afterCommit(\Closure $callback): void;
}

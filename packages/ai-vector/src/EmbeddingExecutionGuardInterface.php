<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Database\DatabaseInterface;

/** @api Coordinates all publication and invalidation for the same storage identity. */
interface EmbeddingExecutionGuardInterface
{
    /** Whether publication and source invalidation share one atomic topology. */
    public function supportsStorage(EmbeddingStorageInterface $storage): bool;

    /** Advance and invalidate inside the source transaction. Never call a provider. */
    public function sourceChanged(string $type, string $id, DatabaseInterface $database, EmbeddingStorageInterface $storage): void;

    public function begin(string $type, string $id): string;

    /** Execute atomically with respect to begin/publication/invalidation; false means superseded. */
    public function runIfCurrent(string $type, string $id, string $token, \Closure $operation): bool;
}

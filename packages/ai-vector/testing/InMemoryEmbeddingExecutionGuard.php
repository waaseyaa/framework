<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Testing;

use Waaseyaa\AI\Vector\EmbeddingExecutionGuardInterface;

/** Test-only, single-process guard. Never a production cross-process guarantee. */
final class InMemoryEmbeddingExecutionGuard implements EmbeddingExecutionGuardInterface
{
    private array $tokens = [];

    public function supportsStorage(\Waaseyaa\AI\Vector\EmbeddingStorageInterface $storage): bool
    {
        return true;
    }

    public function sourceChanged(string $type, string $id, \Waaseyaa\Database\DatabaseInterface $database, \Waaseyaa\AI\Vector\EmbeddingStorageInterface $storage): void
    {
        $this->begin($type, $id);
        $storage->delete($type, $id);
    }

    public function begin(string $type, string $id): string
    {
        return $this->tokens[serialize([$type, $id])] = bin2hex(random_bytes(16));
    }

    public function runIfCurrent(string $type, string $id, string $token, \Closure $operation): bool
    {
        if (($this->tokens[serialize([$type, $id])] ?? null) !== $token) {
            return false;
        }
        $operation();

        return true;
    }
}

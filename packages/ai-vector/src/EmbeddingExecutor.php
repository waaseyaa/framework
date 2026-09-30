<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Entity\EntityInterface;

/** @internal One freshness protocol for lifecycle and operator indexing. */
final class EmbeddingExecutor
{
    public function __construct(
        private readonly EmbeddingStorageInterface $storage,
        private readonly EmbeddingExecutionGuardInterface $guard,
        private readonly EmbeddingIndexPolicy $policy,
        private readonly ?EmbeddingProviderInterface $provider,
    ) {
        if (!$this->guard->supportsStorage($this->storage)) {
            throw new \LogicException('[AIV-EXECUTION-003] Storage and guard must share an atomic publication topology.');
        }
    }

    /** @param \Closure(): ?EntityInterface $load */
    public function index(string $type, string $id, \Closure $load, bool $save = false): string
    {
        $cleanupOnly = $this->guard instanceof DatabaseEmbeddingExecutionGuard && !$this->policy->isDeclared($type);
        $token = $cleanupOnly ? $this->guard->beginForCleanup($type, $id) : $this->guard->begin($type, $id);
        try {
            $entity = $load();
            $text = $entity === null ? null : $this->policy->embeddingText($entity, $this->provider);
            if ($text === null) {
                $current = $this->cleanup($type, $id, $token);

                return $current === 'superseded' ? 'superseded' : ($entity === null ? 'missing' : $current);
            }
            if ($this->provider === null) {
                throw new \LogicException('Embedding provider unavailable.');
            }
            $vector = $save && $this->provider instanceof EmbeddingSaveProviderInterface
                ? $this->provider->embedForSave($text)
                : $this->provider->embed($text);
            $outcome = 'superseded';
            $current = $this->guard->runIfCurrent($type, $id, $token, function () use ($type, $id, $load, $text, $vector, &$outcome): void {
                $served = $load();
                $currentText = $served === null ? null : $this->policy->embeddingText($served, $this->provider);
                if ($currentText !== $text) {
                    $this->storage->delete($type, $id);

                    return;
                }
                $this->storage->store($type, $id, $vector);
                $outcome = 'stored';
            });

            return $current ? $outcome : 'superseded';
        } catch (\Throwable $error) {
            try {
                $this->cleanup($type, $id, $token);
            } catch (\Throwable) {
                throw new \RuntimeException('[AIV-EXECUTION-007] Embedding execution failed and guarded cleanup could not be confirmed.', 0, $error);
            }
            throw $error;
        }
    }

    private function cleanup(string $type, string $id, string $token): string
    {
        if ($this->guard instanceof DatabaseEmbeddingExecutionGuard) {
            return $this->guard->cleanupOutcomeIfCurrent($type, $id, $token, $this->storage);
        }

        return $this->guard->runIfCurrent($type, $id, $token, fn() => $this->storage->delete($type, $id)) ? 'removed' : 'superseded';
    }
}

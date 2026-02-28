<?php

declare(strict_types=1);

namespace Aurora\Telescope;

use Aurora\Telescope\Recorder\CacheRecorder;
use Aurora\Telescope\Recorder\EventRecorder;
use Aurora\Telescope\Recorder\QueryRecorder;
use Aurora\Telescope\Recorder\RequestRecorder;
use Aurora\Telescope\Storage\SqliteTelescopeStore;
use Aurora\Telescope\Storage\TelescopeStoreInterface;

/**
 * Service provider that registers all telescope recorders.
 *
 * Configures the telescope storage backend and wires up all
 * recorders based on provided configuration.
 */
final class TelescopeServiceProvider
{
    private readonly TelescopeStoreInterface $store;
    private ?QueryRecorder $queryRecorder = null;
    private ?EventRecorder $eventRecorder = null;
    private ?RequestRecorder $requestRecorder = null;
    private ?CacheRecorder $cacheRecorder = null;

    /**
     * @param array<string, mixed> $config Telescope configuration.
     */
    public function __construct(
        private readonly array $config = [],
        ?TelescopeStoreInterface $store = null,
    ) {
        $this->store = $store ?? $this->createDefaultStore();
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function getStore(): TelescopeStoreInterface
    {
        return $this->store;
    }

    public function getQueryRecorder(): ?QueryRecorder
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->isRecordingEnabled('queries')) {
            return null;
        }

        if ($this->queryRecorder === null) {
            $this->queryRecorder = new QueryRecorder(
                store: $this->store,
                slowQueryThreshold: (float) ($this->config['record']['slow_query_threshold'] ?? 100.0),
                slowQueriesOnly: (bool) ($this->config['record']['slow_queries_only'] ?? false),
            );
        }

        return $this->queryRecorder;
    }

    public function getEventRecorder(): ?EventRecorder
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->isRecordingEnabled('events')) {
            return null;
        }

        if ($this->eventRecorder === null) {
            $this->eventRecorder = new EventRecorder(store: $this->store);
        }

        return $this->eventRecorder;
    }

    public function getRequestRecorder(): ?RequestRecorder
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->isRecordingEnabled('requests')) {
            return null;
        }

        if ($this->requestRecorder === null) {
            $this->requestRecorder = new RequestRecorder(
                store: $this->store,
                ignorePaths: $this->config['ignore_paths'] ?? [],
            );
        }

        return $this->requestRecorder;
    }

    public function getCacheRecorder(): ?CacheRecorder
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->isRecordingEnabled('cache')) {
            return null;
        }

        if ($this->cacheRecorder === null) {
            $this->cacheRecorder = new CacheRecorder(store: $this->store);
        }

        return $this->cacheRecorder;
    }

    private function isRecordingEnabled(string $recorder): bool
    {
        return (bool) ($this->config['record'][$recorder] ?? true);
    }

    private function createDefaultStore(): TelescopeStoreInterface
    {
        $storagePath = $this->config['storage']['path'] ?? null;

        if ($storagePath !== null) {
            return SqliteTelescopeStore::createFromPath($storagePath);
        }

        return SqliteTelescopeStore::createInMemory();
    }
}

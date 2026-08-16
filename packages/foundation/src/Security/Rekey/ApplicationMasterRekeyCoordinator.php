<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;

/** Restart-safe executor for same-database application-master owner adapters. @api */
final class ApplicationMasterRekeyCoordinator
{
    /** @var array<string, ApplicationMasterRekeyAdapterInterface> */
    private array $adapters = [];

    /**
     * @param list<ApplicationMasterRekeyAdapterInterface> $adapters
     */
    public function __construct(
        private readonly ApplicationMasterRekeyStore $store,
        private readonly ApplicationMasterKeyring $keyring,
        private readonly ApplicationMasterPurposeRegistry $purposes,
        array $adapters,
    ) {
        if (!$purposes->isFrozen() || $purposes->checksum() !== $keyring->purposeRegistryChecksum()) {
            throw new ApplicationMasterRekeyConflictException(
                'The coordinator keyring and frozen purpose registry do not match.',
            );
        }
        $expected = [];
        foreach ($purposes->purposeIds() as $purposeId) {
            $policy = $purposes->policy($purposeId);
            $expected[$policy->adapterId][] = $purposeId;
        }
        ksort($expected, SORT_STRING);
        foreach ($expected as &$purposeIds) {
            sort($purposeIds, SORT_STRING);
        }
        unset($purposeIds);

        foreach ($adapters as $adapter) {
            if ($adapter->databaseAuthority() !== $store->databaseAuthority()) {
                throw new ApplicationMasterRekeyConflictException(
                    'Application-master adapters must use the exact rekey store database authority.',
                );
            }
            $id = $adapter->id();
            if (isset($this->adapters[$id])) {
                throw new ApplicationMasterRekeyConflictException('Application-master adapter IDs must be unique.');
            }
            $actualPurposes = $adapter->purposeIds();
            sort($actualPurposes, SORT_STRING);
            if (($expected[$id] ?? null) !== $actualPurposes) {
                throw new ApplicationMasterRekeyConflictException(
                    'An application-master adapter purpose roster differs from the frozen registry.',
                );
            }
            $this->adapters[$id] = $adapter;
        }
        ksort($this->adapters, SORT_STRING);
        if (array_keys($this->adapters) !== array_keys($expected)) {
            throw new ApplicationMasterRekeyConflictException(
                'Every frozen application-master adapter owner must be composed exactly once.',
            );
        }
    }

    public function snapshotAdapter(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
    ): ApplicationMasterAdapterProgress {
        $adapter = $this->adapter($adapterId);
        $context = $this->context($requestId);
        try {
            $snapshot = $this->executeSnapshot($adapter, $context);
        } catch (ApplicationMasterAdapterExecutionException $failure) {
            $this->persistSnapshotFailureAndRefuse(
                $context,
                $expectedRequestRevision,
                $adapterId,
                $failure,
            );
        }

        return $this->store->recordAdapterSnapshot(
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $adapter->purposeIds(),
            $snapshot->token,
            $snapshot->totalRecords,
        );
    }

    public function transitionNextBatch(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        int $limit,
    ): ApplicationMasterAdapterProgress {
        if ($limit < 1 || $limit > 10_000) {
            throw new \InvalidArgumentException('Application-master rekey batch limits must be between 1 and 10000.');
        }
        $adapter = $this->adapter($adapterId);
        $context = $this->context($requestId);
        $progress = $this->store->requireAdapter($requestId, $adapterId);
        $snapshot = new ApplicationMasterInventorySnapshot($progress->snapshotToken, $progress->totalRecords);

        try {
            return $this->store->commitAdapterBatchOperation(
                $requestId,
                $expectedRequestRevision,
                $adapterId,
                $progress->revision,
                $progress->cursor,
                fn(): ApplicationMasterBatchResult => $this->executeTransition(
                    $adapter,
                    $context,
                    $snapshot,
                    $progress->cursor,
                    $limit,
                ),
            );
        } catch (ApplicationMasterAdapterExecutionException $failure) {
            $this->persistFailureAndRefuse(
                $context,
                $expectedRequestRevision,
                $progress,
                'adapter-transition-failed',
                $failure,
            );
        }
    }

    public function snapshotRollbackAdapter(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
    ): ApplicationMasterAdapterProgress {
        $adapter = $this->adapter($adapterId);
        $context = $this->context($requestId);
        $progress = $this->store->requireAdapter($requestId, $adapterId);
        try {
            $snapshot = $this->executeRollbackSnapshot($adapter, $context);
        } catch (ApplicationMasterAdapterExecutionException $failure) {
            $this->persistRollbackFailureAndRefuse(
                $context,
                $expectedRequestRevision,
                $progress,
                'adapter-rollback-snapshot-failed',
                $failure,
            );
        }

        return $this->store->recordAdapterRollbackSnapshot(
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $progress->revision,
            $snapshot->token,
            $snapshot->totalRecords,
        );
    }

    public function rollbackNextBatch(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        int $limit,
    ): ApplicationMasterAdapterProgress {
        if ($limit < 1 || $limit > 10_000) {
            throw new \InvalidArgumentException('Application-master rollback batch limits must be between 1 and 10000.');
        }
        $adapter = $this->adapter($adapterId);
        $context = $this->context($requestId);
        $progress = $this->store->requireAdapter($requestId, $adapterId);
        if ($progress->rollbackSnapshotToken === null) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master rollback adapter has no immutable snapshot.',
            );
        }
        $snapshot = new ApplicationMasterInventorySnapshot(
            $progress->rollbackSnapshotToken,
            $progress->rollbackTotalRecords,
        );

        try {
            return $this->store->commitAdapterRollbackOperation(
                $requestId,
                $expectedRequestRevision,
                $adapterId,
                $progress->revision,
                $progress->rollbackCursor,
                fn(): ApplicationMasterBatchResult => $this->executeRollback(
                    $adapter,
                    $context,
                    $snapshot,
                    $progress->rollbackCursor,
                    $limit,
                ),
            );
        } catch (ApplicationMasterAdapterExecutionException $failure) {
            $this->persistRollbackFailureAndRefuse(
                $context,
                $expectedRequestRevision,
                $progress,
                'adapter-rollback-failed',
                $failure,
            );
        }
    }

    public function completeRollbackAdapter(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
    ): ApplicationMasterRekeyRecord {
        $adapter = $this->adapter($adapterId);
        $context = $this->context($requestId);
        $progress = $this->store->requireAdapter($requestId, $adapterId);
        if ($progress->rollbackSnapshotToken === null) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master rollback adapter has no immutable snapshot.',
            );
        }
        $snapshot = new ApplicationMasterInventorySnapshot(
            $progress->rollbackSnapshotToken,
            $progress->rollbackTotalRecords,
        );
        try {
            $results = $this->executeRollbackVerification($adapter, $context, $snapshot);
        } catch (ApplicationMasterAdapterExecutionException $failure) {
            $this->persistRollbackFailureAndRefuse(
                $context,
                $expectedRequestRevision,
                $progress,
                'adapter-rollback-verification-failed',
                $failure,
            );
        }

        return $this->store->completeRollbackAdapter(
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $progress->revision,
            $progress->rollbackCursor,
            $results,
        );
    }

    public function completeRollback(
        string $requestId,
        int $expectedRequestRevision,
        string $completionHash,
        int $completedAt,
    ): ApplicationMasterRekeyRecord {
        return $this->store->completeRollback(
            $requestId,
            $expectedRequestRevision,
            $completionHash,
            $completedAt,
        );
    }

    public function completeAdapter(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
    ): ApplicationMasterRekeyRecord {
        $this->adapter($adapterId);
        $progress = $this->store->requireAdapter($requestId, $adapterId);

        return $this->store->completeAdapter(
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $progress->revision,
            $progress->cursor,
        );
    }

    public function verifyAdapter(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
    ): ApplicationMasterRekeyRecord {
        $adapter = $this->adapter($adapterId);
        $context = $this->context($requestId);
        $progress = $this->store->requireAdapter($requestId, $adapterId);
        if ($progress->status !== 'complete') {
            throw new ApplicationMasterRekeyConflictException('An incomplete adapter cannot verify its purposes.');
        }
        $snapshot = new ApplicationMasterInventorySnapshot($progress->snapshotToken, $progress->totalRecords);
        try {
            $results = $this->executeVerification($adapter, $context, $snapshot);
        } catch (ApplicationMasterAdapterExecutionException $failure) {
            $this->persistFailureAndRefuse(
                $context,
                $expectedRequestRevision,
                $progress,
                'adapter-verification-failed',
                $failure,
            );
        }
        $purposeIds = array_keys($results);
        sort($purposeIds, SORT_STRING);
        $expected = $adapter->purposeIds();
        sort($expected, SORT_STRING);
        if ($purposeIds !== $expected) {
            throw new ApplicationMasterRekeyConflictException(
                'Adapter verification must return every owned purpose exactly once.',
            );
        }

        foreach ($expected as $purposeId) {
            $result = $results[$purposeId] ?? null;
            if (!$result instanceof ApplicationMasterPurposeVerification) {
                throw new ApplicationMasterRekeyConflictException('Adapter verification evidence is malformed.');
            }
        }

        return $this->store->recordAdapterVerifications(
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $results,
        );
    }

    private function context(string $requestId): ApplicationMasterRekeyContext
    {
        $request = $this->store->require($requestId);
        $rollbackTopology = in_array($request->state, [
            ApplicationMasterRekeyState::RollingBack,
            ApplicationMasterRekeyState::RolledBack,
        ], true);
        $activeVersion = $rollbackTopology ? $request->fromVersion : $request->toVersion;
        $readableVersion = $rollbackTopology ? $request->toVersion : $request->fromVersion;
        if ($request->registryChecksum !== $this->purposes->checksum()
            || $activeVersion !== $this->keyring->activeVersion()
            || !in_array($readableVersion, $this->keyring->readableVersions(), true)) {
            throw new ApplicationMasterRekeyConflictException(
                'The live keyring does not match the immutable rekey request.',
            );
        }
        if ($rollbackTopology
            && ($this->store->masterVersionState($activeVersion) !== 'active-write'
                || $this->store->masterVersionState($readableVersion) !== 'failed-read-only')) {
            throw new ApplicationMasterRekeyConflictException(
                'The live application-master version ledger does not match the rollback request.',
            );
        }

        return new ApplicationMasterRekeyContext(
            $request,
            $this->keyring,
            $this->store->databaseAuthority(),
        );
    }

    private function adapter(string $adapterId): ApplicationMasterRekeyAdapterInterface
    {
        return $this->adapters[$adapterId]
            ?? throw new ApplicationMasterRekeyConflictException('The requested rekey adapter is not composed.');
    }

    private function executeTransition(
        ApplicationMasterRekeyAdapterInterface $adapter,
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        try {
            return $adapter->transitionBatch($context, $snapshot, $cursor, $limit);
        } catch (\Throwable $failure) {
            throw new ApplicationMasterAdapterExecutionException('transition', $failure::class);
        }
    }

    private function executeSnapshot(
        ApplicationMasterRekeyAdapterInterface $adapter,
        ApplicationMasterRekeyContext $context,
    ): ApplicationMasterInventorySnapshot {
        try {
            return $adapter->snapshot($context);
        } catch (\Throwable $failure) {
            throw new ApplicationMasterAdapterExecutionException('snapshot', $failure::class);
        }
    }

    private function executeRollbackSnapshot(
        ApplicationMasterRekeyAdapterInterface $adapter,
        ApplicationMasterRekeyContext $context,
    ): ApplicationMasterInventorySnapshot {
        try {
            return $adapter->rollbackSnapshot($context);
        } catch (\Throwable $failure) {
            throw new ApplicationMasterAdapterExecutionException('rollback-snapshot', $failure::class);
        }
    }

    private function executeRollback(
        ApplicationMasterRekeyAdapterInterface $adapter,
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        try {
            return $adapter->rollbackBatch($context, $snapshot, $cursor, $limit);
        } catch (\Throwable $failure) {
            throw new ApplicationMasterAdapterExecutionException('rollback', $failure::class);
        }
    }

    /** @return array<string, ApplicationMasterPurposeVerification> */
    private function executeRollbackVerification(
        ApplicationMasterRekeyAdapterInterface $adapter,
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        try {
            return $adapter->verifyRollback($context, $snapshot);
        } catch (\Throwable $failure) {
            throw new ApplicationMasterAdapterExecutionException('rollback-verification', $failure::class);
        }
    }

    /** @return array<string, ApplicationMasterPurposeVerification> */
    private function executeVerification(
        ApplicationMasterRekeyAdapterInterface $adapter,
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        try {
            return $adapter->verify($context, $snapshot);
        } catch (\Throwable $failure) {
            throw new ApplicationMasterAdapterExecutionException('verification', $failure::class);
        }
    }

    /** @return never */
    private function persistFailureAndRefuse(
        ApplicationMasterRekeyContext $context,
        int $expectedRequestRevision,
        ApplicationMasterAdapterProgress $progress,
        string $failureCode,
        ApplicationMasterAdapterExecutionException $failure,
    ): never {
        $evidenceHash = hash('sha256', json_encode([
            'format' => 'waaseyaa.application-master.adapter-failure.v1',
            'request_digest' => $context->request->requestDigest,
            'adapter_id' => $progress->adapterId,
            'cursor' => $progress->cursor,
            'operation' => $failure->operation,
            'failure_class' => $failure->failureClass,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->store->recordAdapterFailure(
            $context->request->requestId,
            $expectedRequestRevision,
            $progress->adapterId,
            $progress->revision,
            $progress->cursor,
            $failureCode,
            $evidenceHash,
        );

        throw new ApplicationMasterRekeyConflictException(
            'The application-master adapter failure was recorded and must be resolved before retry.',
        );
    }

    /** @return never */
    private function persistSnapshotFailureAndRefuse(
        ApplicationMasterRekeyContext $context,
        int $expectedRequestRevision,
        string $adapterId,
        ApplicationMasterAdapterExecutionException $failure,
    ): never {
        $evidenceHash = hash('sha256', json_encode([
            'format' => 'waaseyaa.application-master.adapter-failure.v1',
            'request_digest' => $context->request->requestDigest,
            'adapter_id' => $adapterId,
            'cursor' => null,
            'operation' => $failure->operation,
            'failure_class' => $failure->failureClass,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->store->recordAdapterSnapshotFailure(
            $context->request->requestId,
            $expectedRequestRevision,
            $adapterId,
            'adapter-snapshot-failed',
            $evidenceHash,
        );

        throw new ApplicationMasterRekeyConflictException(
            'The application-master adapter snapshot failure was recorded and must be resolved before retry.',
        );
    }

    /** @return never */
    private function persistRollbackFailureAndRefuse(
        ApplicationMasterRekeyContext $context,
        int $expectedRequestRevision,
        ApplicationMasterAdapterProgress $progress,
        string $failureCode,
        ApplicationMasterAdapterExecutionException $failure,
    ): never {
        $evidenceHash = hash('sha256', json_encode([
            'format' => 'waaseyaa.application-master.adapter-failure.v1',
            'request_digest' => $context->request->requestDigest,
            'adapter_id' => $progress->adapterId,
            'cursor' => $progress->rollbackCursor,
            'operation' => $failure->operation,
            'failure_class' => $failure->failureClass,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->store->recordAdapterFailure(
            $context->request->requestId,
            $expectedRequestRevision,
            $progress->adapterId,
            $progress->revision,
            $progress->rollbackCursor,
            $failureCode,
            $evidenceHash,
        );

        throw new ApplicationMasterRekeyConflictException(
            'The application-master rollback adapter failure was recorded and must be resolved before retry.',
        );
    }
}

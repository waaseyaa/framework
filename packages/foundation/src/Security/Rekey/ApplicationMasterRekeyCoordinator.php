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
            if ($adapter->databaseIdentity() !== $store->databaseIdentity()) {
                throw new ApplicationMasterRekeyConflictException(
                    'Application-master adapters must share the rekey store database authority.',
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
        $snapshot = $adapter->snapshot($context);

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

        return $this->store->commitAdapterBatchOperation(
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $progress->revision,
            $progress->cursor,
            fn(): ApplicationMasterBatchResult => $adapter->transitionBatch(
                $context,
                $snapshot,
                $progress->cursor,
                $limit,
            ),
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
        $results = $adapter->verify($context, $snapshot);
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
        if ($request->registryChecksum !== $this->purposes->checksum()
            || $request->toVersion !== $this->keyring->activeVersion()
            || !in_array($request->fromVersion, $this->keyring->readableVersions(), true)) {
            throw new ApplicationMasterRekeyConflictException(
                'The live keyring does not match the immutable rekey request.',
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
}

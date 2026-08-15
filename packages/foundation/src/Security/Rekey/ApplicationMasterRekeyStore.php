<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security\Rekey;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DatabaseIdentityProviderInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;

/** Migration-backed CAS projections and append-only evidence for CFG-04 rekey. @api */
final class ApplicationMasterRekeyStore
{
    private const string MIGRATION_ID = 'waaseyaa/foundation:2026_08_15_000002_application_master_rekey';
    private const string ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const string REQUEST_TABLE = 'waaseyaa_application_master_rekey';
    private const string VERSION_TABLE = 'waaseyaa_application_master_version';
    private const string PURPOSE_TABLE = 'waaseyaa_application_master_rekey_purpose';
    private const string ADAPTER_TABLE = 'waaseyaa_application_master_rekey_adapter';
    private const string FAILURE_TABLE = 'waaseyaa_application_master_rekey_failure';
    private const string VERIFICATION_TABLE = 'waaseyaa_application_master_rekey_verification';
    private const string GATE_TABLE = 'waaseyaa_application_master_rekey_gate';
    private const string EVENT_TABLE = 'waaseyaa_application_master_rekey_event';

    private bool $schemaVerified = false;

    /** @var array<string, ApplicationMasterRekeyRecord> */
    private array $recordCache = [];

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?\Closure $clock = null,
    ) {}

    public function databaseIdentity(): string
    {
        if (!$this->database instanceof DatabaseIdentityProviderInterface) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master rekey store requires a stable database identity.',
            );
        }

        return $this->database->databaseIdentity();
    }

    /** Exact transaction authority adapters must use for owner CAS effects. */
    public function databaseAuthority(): DatabaseInterface
    {
        return $this->database;
    }

    public function prepare(
        ApplicationMasterRekeyRequest $request,
        ApplicationMasterPurposeRegistry $purposes,
    ): ApplicationMasterRekeyRecord {
        $this->ensureSchema();
        if (!$purposes->isFrozen() || $purposes->checksum() !== $request->registryChecksum) {
            throw new ApplicationMasterRekeyConflictException(
                'The frozen application-master purpose registry does not match the authorized request.',
            );
        }

        $existing = $this->findRecord($request->requestId);
        if ($existing !== null) {
            if (!hash_equals($existing->requestDigest, $request->digest())) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master rekey request id is already bound to different immutable bytes.',
                );
            }

            return $this->recordCache[$request->requestId] ??= $existing;
        }

        try {
            $record = $this->transactional(function () use ($request, $purposes): ApplicationMasterRekeyRecord {
                $this->database->insert(self::REQUEST_TABLE)->values([
                    'request_id' => $request->requestId,
                    'request_digest' => $request->digest(),
                    'from_version' => $request->fromVersion,
                    'to_version' => $request->toVersion,
                    'registry_checksum' => $request->registryChecksum,
                    'authorization_digest' => $request->authorizationDigest,
                    'actor' => $request->actor,
                    'rollback_deadline' => $request->rollbackDeadline,
                    'retention_deadline' => $request->retentionDeadline,
                    'state' => ApplicationMasterRekeyState::PrepareAndAuthorize->value,
                    'revision' => 1,
                    'unresolved_failures' => 0,
                    'created_at' => $request->createdAt,
                    'updated_at' => $request->createdAt,
                ])->execute();

                foreach ($purposes->purposeIds() as $purposeId) {
                    $canonical = $this->canonicalJson($purposes->policy($purposeId)->canonicalRecord());
                    $this->database->insert(self::PURPOSE_TABLE)->values([
                        'request_id' => $request->requestId,
                        'purpose_id' => $purposeId,
                        'canonical_policy_json' => $canonical,
                        'policy_checksum' => hash('sha256', $canonical),
                    ])->execute();
                }
                $this->appendEvent(
                    $request->requestId,
                    'prepared',
                    [
                        'request_digest' => $request->digest(),
                        'registry_checksum' => $request->registryChecksum,
                        'from_version' => $request->fromVersion,
                        'to_version' => $request->toVersion,
                    ],
                    $request->createdAt,
                );

                return $this->requireFresh($request->requestId);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $winner = $this->findRecord($request->requestId);
            if ($winner !== null && hash_equals($winner->requestDigest, $request->digest())) {
                return $this->recordCache[$request->requestId] = $winner;
            }
            throw new ApplicationMasterRekeyConflictException(
                'The application-master request conflicted with another immutable request.',
                previous: $exception,
            );
        }

        return $this->recordCache[$request->requestId] = $record;
    }

    public function require(string $requestId): ApplicationMasterRekeyRecord
    {
        $this->ensureSchema();

        return $this->recordCache[$requestId] ??= $this->requireFresh($requestId);
    }

    public function installActive(
        string $requestId,
        int $expectedRequestRevision,
        string $fromReferenceFingerprint,
        string $toReferenceFingerprint,
        int $activatedAt,
    ): ApplicationMasterRekeyRecord {
        self::assertHash($fromReferenceFingerprint, 'predecessor reference fingerprint');
        self::assertHash($toReferenceFingerprint, 'successor reference fingerprint');
        if (hash_equals($fromReferenceFingerprint, $toReferenceFingerprint)) {
            throw new \InvalidArgumentException('Application-master versions require distinct external references.');
        }

        return $this->mutateRequest(
            $requestId,
            $expectedRequestRevision,
            [ApplicationMasterRekeyState::PrepareAndAuthorize],
            ApplicationMasterRekeyState::InstallNewActiveWithOldLegacyReadVerify,
            'active-installed',
            function (ApplicationMasterRekeyRecord $record) use (
                $fromReferenceFingerprint,
                $toReferenceFingerprint,
                $activatedAt,
            ): array {
                $existingFrom = $this->fetchOne(
                    'SELECT * FROM ' . self::VERSION_TABLE . ' WHERE master_version = :version',
                    ['version' => $record->fromVersion],
                );
                $maximum = $this->fetchOne('SELECT MAX(master_version) AS maximum FROM ' . self::VERSION_TABLE, []);
                $maximumVersion = (int) ($maximum['maximum'] ?? 0);
                if ($existingFrom === null) {
                    if ($maximumVersion !== 0) {
                        throw new ApplicationMasterRekeyConflictException(
                            'The requested predecessor is not the recorded active master.',
                        );
                    }
                    $this->database->insert(self::VERSION_TABLE)->values([
                        'master_version' => $record->fromVersion,
                        'state' => 'legacy-read-verify',
                        'reference_fingerprint' => $fromReferenceFingerprint,
                        'activated_at' => null,
                        'retained_until' => $record->retentionDeadline,
                        'revoked_at' => null,
                    ])->execute();
                } else {
                    if ((string) $existingFrom['state'] !== 'active-write'
                        || !hash_equals((string) $existingFrom['reference_fingerprint'], $fromReferenceFingerprint)
                        || $record->toVersion <= $maximumVersion) {
                        throw new ApplicationMasterRekeyConflictException(
                            'The requested predecessor identity is stale or the successor version is not monotonic.',
                        );
                    }
                    $retired = $this->database->update(self::VERSION_TABLE)->fields([
                        'state' => 'legacy-read-verify',
                        'retained_until' => $record->retentionDeadline,
                    ])->condition('master_version', $record->fromVersion)
                        ->condition('state', 'active-write')
                        ->condition('reference_fingerprint', $fromReferenceFingerprint)
                        ->execute();
                    if ($retired !== 1) {
                        throw new ApplicationMasterRekeyConflictException(
                            'The active master changed before successor installation.',
                        );
                    }
                }
                $this->database->insert(self::VERSION_TABLE)->values([
                    'master_version' => $record->toVersion,
                    'state' => 'active-write',
                    'reference_fingerprint' => $toReferenceFingerprint,
                    'activated_at' => $activatedAt,
                    'retained_until' => null,
                    'revoked_at' => null,
                ])->execute();

                return [
                    'from_reference_fingerprint' => $fromReferenceFingerprint,
                    'to_reference_fingerprint' => $toReferenceFingerprint,
                    'activated_at' => $activatedAt,
                ];
            },
            $activatedAt,
        );
    }

    /**
     * @param list<string> $purposeIds
     */
    public function recordAdapterSnapshot(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        array $purposeIds,
        string $snapshotToken,
        int $totalRecords,
    ): ApplicationMasterAdapterProgress {
        self::assertStableIdentifier($adapterId, 'adapter id');
        self::assertHash($snapshotToken, 'snapshot token');
        if ($totalRecords < 0 || $purposeIds === []) {
            throw new \InvalidArgumentException('Application-master adapter snapshots require purposes and a non-negative total.');
        }
        $purposeIds = array_values(array_unique($purposeIds));
        sort($purposeIds, SORT_STRING);
        $this->assertAdapterPurposes($requestId, $adapterId, $purposeIds);

        $counts = array_fill_keys($purposeIds, 0);
        $this->mutateRequest(
            $requestId,
            $expectedRequestRevision,
            [
                ApplicationMasterRekeyState::InstallNewActiveWithOldLegacyReadVerify,
                ApplicationMasterRekeyState::EnumerateSnapshot,
                ApplicationMasterRekeyState::TransitionBoundedBatches,
            ],
            ApplicationMasterRekeyState::EnumerateSnapshot,
            'adapter-snapshotted',
            function () use ($requestId, $adapterId, $purposeIds, $snapshotToken, $totalRecords, $counts): array {
                $this->database->insert(self::ADAPTER_TABLE)->values([
                    'request_id' => $requestId,
                    'adapter_id' => $adapterId,
                    'purpose_ids_json' => $this->canonicalJson($purposeIds),
                    'snapshot_token' => $snapshotToken,
                    'total_records' => $totalRecords,
                    'cursor' => null,
                    'transitioned_records' => 0,
                    'purpose_counts_json' => $this->canonicalJson($counts),
                    'batch_chain_hash' => self::ZERO_HASH,
                    'status' => 'snapshotted',
                    'revision' => 1,
                    'unresolved_failures' => 0,
                ])->execute();

                return [
                    'adapter_id' => $adapterId,
                    'purpose_ids' => $purposeIds,
                    'snapshot_token' => $snapshotToken,
                    'total_records' => $totalRecords,
                ];
            },
        );

        return $this->requireAdapter($requestId, $adapterId);
    }

    /**
     * @param array<string, int> $purposeCountDeltas
     */
    public function commitAdapterBatch(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        int $expectedAdapterRevision,
        ?string $expectedCursor,
        string $nextCursor,
        int $transitionedRecords,
        array $purposeCountDeltas,
        string $batchCommitment,
    ): ApplicationMasterAdapterProgress {
        self::assertHash($batchCommitment, 'batch commitment');
        if ($nextCursor === ''
            || strlen($nextCursor) > 512
            || $nextCursor === $expectedCursor
            || $transitionedRecords < 1) {
            throw new \InvalidArgumentException('Application-master batches require a bounded next cursor and positive row count.');
        }

        $this->ensureSchema();
        $this->transactional(function () use (
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $expectedAdapterRevision,
            $expectedCursor,
            $nextCursor,
            $transitionedRecords,
            $purposeCountDeltas,
            $batchCommitment,
        ): void {
            $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                ApplicationMasterRekeyState::EnumerateSnapshot,
                ApplicationMasterRekeyState::TransitionBoundedBatches,
            ]);
            $progress = $this->requireAdapterFresh($requestId, $adapterId);
            if ($progress->revision !== $expectedAdapterRevision
                || $progress->cursor !== $expectedCursor
                || $progress->status === 'complete'
                || $progress->unresolvedFailures !== 0) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master adapter cursor or revision is stale.',
                );
            }
            $this->assertPurposeDeltas($progress, $purposeCountDeltas);
            $newTransitioned = $progress->transitionedRecords + $transitionedRecords;
            if ($newTransitioned > $progress->totalRecords) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master batch exceeds its immutable inventory total.',
                );
            }
            $counts = $progress->purposeCounts;
            foreach ($purposeCountDeltas as $purpose => $delta) {
                $counts[$purpose] += $delta;
            }
            ksort($counts, SORT_STRING);
            $chainHash = hash('sha256', implode("\0", [
                'waaseyaa.application-master.batch-chain.v1',
                $progress->batchChainHash,
                $batchCommitment,
                $nextCursor,
                (string) $newTransitioned,
                $this->canonicalJson($counts),
            ]));

            $update = $this->database->update(self::ADAPTER_TABLE)->fields([
                'cursor' => $nextCursor,
                'transitioned_records' => $newTransitioned,
                'purpose_counts_json' => $this->canonicalJson($counts),
                'batch_chain_hash' => $chainHash,
                'status' => 'transitioning',
                'revision' => $expectedAdapterRevision + 1,
            ])->condition('request_id', $requestId)
                ->condition('adapter_id', $adapterId)
                ->condition('revision', $expectedAdapterRevision);
            $update = $expectedCursor === null
                ? $update->condition('cursor', null, 'IS NULL')
                : $update->condition('cursor', $expectedCursor);
            if ($update->execute() !== 1) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master adapter cursor changed before batch commit.',
                );
            }
            $this->advanceRequestProjection(
                $record,
                ApplicationMasterRekeyState::TransitionBoundedBatches,
                $this->now(),
            );
            $this->appendEvent($requestId, 'adapter-batch-committed', [
                'adapter_id' => $adapterId,
                'expected_cursor' => $expectedCursor,
                'next_cursor' => $nextCursor,
                'transitioned_records' => $transitionedRecords,
                'purpose_count_deltas' => $purposeCountDeltas,
                'batch_commitment' => $batchCommitment,
                'batch_chain_hash' => $chainHash,
            ]);
        });
        unset($this->recordCache[$requestId]);

        return $this->requireAdapter($requestId, $adapterId);
    }

    /**
     * Execute owner CAS effects and persist their validated cursor evidence in
     * one transaction on the exact database authority exposed in the context.
     *
     * @param \Closure(): ApplicationMasterBatchResult $operation
     */
    public function commitAdapterBatchOperation(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        int $expectedAdapterRevision,
        ?string $expectedCursor,
        \Closure $operation,
    ): ApplicationMasterAdapterProgress {
        $this->ensureSchema();
        $this->transactional(function () use (
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $expectedAdapterRevision,
            $expectedCursor,
            $operation,
        ): void {
            $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                ApplicationMasterRekeyState::EnumerateSnapshot,
                ApplicationMasterRekeyState::TransitionBoundedBatches,
            ]);
            $progress = $this->requireAdapterFresh($requestId, $adapterId);
            if ($progress->revision !== $expectedAdapterRevision
                || $progress->cursor !== $expectedCursor
                || $progress->status === 'complete'
                || $progress->unresolvedFailures !== 0) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master adapter cursor or revision is stale.',
                );
            }

            $result = $operation();
            if ($result->nextCursor === $expectedCursor) {
                throw new ApplicationMasterRekeyConflictException(
                    'An application-master batch must advance its durable cursor.',
                );
            }
            $this->assertPurposeDeltas($progress, $result->purposeCountDeltas);
            $newTransitioned = $progress->transitionedRecords + $result->transitionedRecords;
            if ($newTransitioned > $progress->totalRecords) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master batch exceeds its immutable inventory total.',
                );
            }
            $counts = $progress->purposeCounts;
            foreach ($result->purposeCountDeltas as $purpose => $delta) {
                $counts[$purpose] += $delta;
            }
            ksort($counts, SORT_STRING);
            $chainHash = hash('sha256', implode("\0", [
                'waaseyaa.application-master.batch-chain.v1',
                $progress->batchChainHash,
                $result->batchCommitment,
                $result->nextCursor,
                (string) $newTransitioned,
                $this->canonicalJson($counts),
            ]));

            $update = $this->database->update(self::ADAPTER_TABLE)->fields([
                'cursor' => $result->nextCursor,
                'transitioned_records' => $newTransitioned,
                'purpose_counts_json' => $this->canonicalJson($counts),
                'batch_chain_hash' => $chainHash,
                'status' => 'transitioning',
                'revision' => $expectedAdapterRevision + 1,
            ])->condition('request_id', $requestId)
                ->condition('adapter_id', $adapterId)
                ->condition('revision', $expectedAdapterRevision);
            $update = $expectedCursor === null
                ? $update->condition('cursor', null, 'IS NULL')
                : $update->condition('cursor', $expectedCursor);
            if ($update->execute() !== 1) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master adapter cursor changed before batch commit.',
                );
            }
            $now = $this->now();
            $this->advanceRequestProjection($record, ApplicationMasterRekeyState::TransitionBoundedBatches, $now);
            $this->appendEvent($requestId, 'adapter-batch-committed', [
                'adapter_id' => $adapterId,
                'expected_cursor' => $expectedCursor,
                'next_cursor' => $result->nextCursor,
                'transitioned_records' => $result->transitionedRecords,
                'purpose_count_deltas' => $result->purposeCountDeltas,
                'batch_commitment' => $result->batchCommitment,
                'batch_chain_hash' => $chainHash,
            ], $now);
        });
        unset($this->recordCache[$requestId]);

        return $this->requireAdapter($requestId, $adapterId);
    }

    public function completeAdapter(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        int $expectedAdapterRevision,
        ?string $expectedCursor,
    ): ApplicationMasterRekeyRecord {
        $this->ensureSchema();

        return $this->transactional(function () use (
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $expectedAdapterRevision,
            $expectedCursor,
        ): ApplicationMasterRekeyRecord {
            $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                ApplicationMasterRekeyState::EnumerateSnapshot,
                ApplicationMasterRekeyState::TransitionBoundedBatches,
            ]);
            $progress = $this->requireAdapterFresh($requestId, $adapterId);
            if ($progress->revision !== $expectedAdapterRevision
                || $progress->cursor !== $expectedCursor
                || $progress->transitionedRecords !== $progress->totalRecords
                || $progress->unresolvedFailures !== 0) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master adapter is not complete at the expected cursor.',
                );
            }
            $update = $this->database->update(self::ADAPTER_TABLE)->fields([
                'status' => 'complete',
                'revision' => $expectedAdapterRevision + 1,
            ])->condition('request_id', $requestId)
                ->condition('adapter_id', $adapterId)
                ->condition('revision', $expectedAdapterRevision);
            $update = $expectedCursor === null
                ? $update->condition('cursor', null, 'IS NULL')
                : $update->condition('cursor', $expectedCursor);
            if ($update->execute() !== 1) {
                throw new ApplicationMasterRekeyConflictException('The adapter completion cursor changed.');
            }

            $state = $this->allAdaptersComplete($requestId)
                ? ApplicationMasterRekeyState::VerifyEveryPurpose
                : ApplicationMasterRekeyState::TransitionBoundedBatches;
            $now = $this->now();
            $this->advanceRequestProjection($record, $state, $now);
            $this->appendEvent($requestId, 'adapter-completed', [
                'adapter_id' => $adapterId,
                'cursor' => $expectedCursor,
                'transitioned_records' => $progress->transitionedRecords,
                'purpose_counts' => $progress->purposeCounts,
                'batch_chain_hash' => $progress->batchChainHash,
            ], $now);

            unset($this->recordCache[$requestId]);

            return $this->requireFresh($requestId);
        });
    }

    public function recordAdapterFailure(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        int $expectedAdapterRevision,
        ?string $expectedCursor,
        string $failureCode,
        string $evidenceHash,
    ): ApplicationMasterRekeyRecord {
        self::assertStableIdentifier($adapterId, 'adapter id');
        self::assertStableIdentifier($failureCode, 'failure code');
        self::assertHash($evidenceHash, 'failure evidence hash');
        $this->ensureSchema();

        try {
            return $this->transactional(function () use (
                $requestId,
                $expectedRequestRevision,
                $adapterId,
                $expectedAdapterRevision,
                $expectedCursor,
                $failureCode,
                $evidenceHash,
            ): ApplicationMasterRekeyRecord {
                $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                    ApplicationMasterRekeyState::EnumerateSnapshot,
                    ApplicationMasterRekeyState::TransitionBoundedBatches,
                    ApplicationMasterRekeyState::VerifyEveryPurpose,
                ]);
                $progress = $this->requireAdapterFresh($requestId, $adapterId);
                if ($progress->revision !== $expectedAdapterRevision
                    || $progress->cursor !== $expectedCursor
                    || $progress->unresolvedFailures !== 0) {
                    throw new ApplicationMasterRekeyConflictException(
                        'The application-master adapter failure projection is stale.',
                    );
                }

                $now = $this->now();
                $this->database->insert(self::FAILURE_TABLE)->values([
                    'request_id' => $requestId,
                    'adapter_id' => $adapterId,
                    'cursor' => $expectedCursor,
                    'failure_code' => $failureCode,
                    'evidence_hash' => $evidenceHash,
                    'opened_at' => $now,
                    'resolved_at' => null,
                    'resolution_hash' => null,
                ])->execute();
                $this->updateAdapterFailureCount($progress, 1);
                $this->updateRequestFailureCount($record, 1, $now);
                $this->appendEvent($requestId, 'adapter-failure-recorded', [
                    'adapter_id' => $adapterId,
                    'cursor' => $expectedCursor,
                    'failure_code' => $failureCode,
                    'evidence_hash' => $evidenceHash,
                ], $now);
                unset($this->recordCache[$requestId]);

                return $this->requireFresh($requestId);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master adapter already has an unresolved failure.',
                previous: $exception,
            );
        }
    }

    public function resolveAdapterFailure(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        int $expectedAdapterRevision,
        string $resolutionHash,
    ): ApplicationMasterRekeyRecord {
        self::assertStableIdentifier($adapterId, 'adapter id');
        self::assertHash($resolutionHash, 'failure resolution hash');
        $this->ensureSchema();

        return $this->transactional(function () use (
            $requestId,
            $expectedRequestRevision,
            $adapterId,
            $expectedAdapterRevision,
            $resolutionHash,
        ): ApplicationMasterRekeyRecord {
            $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                ApplicationMasterRekeyState::EnumerateSnapshot,
                ApplicationMasterRekeyState::TransitionBoundedBatches,
                ApplicationMasterRekeyState::VerifyEveryPurpose,
            ]);
            $progress = $this->requireAdapterFresh($requestId, $adapterId);
            if ($record->unresolvedFailures < 1
                || $progress->revision !== $expectedAdapterRevision
                || $progress->unresolvedFailures !== 1) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master adapter has no exact unresolved failure to resolve.',
                );
            }
            $failure = $this->fetchOne(
                'SELECT failure_id, cursor, failure_code, evidence_hash FROM ' . self::FAILURE_TABLE
                    . ' WHERE request_id = :request AND adapter_id = :adapter AND resolved_at IS NULL',
                ['request' => $requestId, 'adapter' => $adapterId],
            );
            if ($failure === null || $progress->cursor !== $failure['cursor']) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master adapter open failure does not match its durable cursor.',
                );
            }

            $now = $this->now();
            $updated = $this->database->update(self::FAILURE_TABLE)->fields([
                'resolved_at' => $now,
                'resolution_hash' => $resolutionHash,
            ])->condition('failure_id', (int) $failure['failure_id'])
                ->condition('resolved_at', null, 'IS NULL')
                ->execute();
            if ($updated !== 1) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master adapter failure changed before resolution.',
                );
            }
            $this->updateAdapterFailureCount($progress, -1);
            $this->updateRequestFailureCount($record, -1, $now);
            $this->appendEvent($requestId, 'adapter-failure-resolved', [
                'adapter_id' => $adapterId,
                'cursor' => $progress->cursor,
                'failure_code' => (string) $failure['failure_code'],
                'evidence_hash' => (string) $failure['evidence_hash'],
                'resolution_hash' => $resolutionHash,
            ], $now);
            unset($this->recordCache[$requestId]);

            return $this->requireFresh($requestId);
        });
    }

    public function recordPurposeVerification(
        string $requestId,
        int $expectedRequestRevision,
        string $purposeId,
        int $verifiedRecords,
        string $verificationHash,
    ): ApplicationMasterRekeyRecord {
        self::assertHash($verificationHash, 'verification hash');
        if ($verifiedRecords < 0) {
            throw new \InvalidArgumentException('Application-master verification counts cannot be negative.');
        }
        $this->ensureSchema();

        return $this->transactional(function () use (
            $requestId,
            $expectedRequestRevision,
            $purposeId,
            $verifiedRecords,
            $verificationHash,
        ): ApplicationMasterRekeyRecord {
            $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                ApplicationMasterRekeyState::VerifyEveryPurpose,
                ApplicationMasterRekeyState::ReconcileWritersAndWorkers,
            ]);
            $progress = $this->adapterForPurpose($requestId, $purposeId);
            if ($progress->status !== 'complete'
                || $progress->unresolvedFailures !== 0
                || ($progress->purposeCounts[$purposeId] ?? null) !== $verifiedRecords) {
                throw new ApplicationMasterRekeyConflictException(
                    'Purpose verification does not match its completed adapter projection.',
                );
            }
            try {
                $this->database->insert(self::VERIFICATION_TABLE)->values([
                    'request_id' => $requestId,
                    'purpose_id' => $purposeId,
                    'verified_records' => $verifiedRecords,
                    'verification_hash' => $verificationHash,
                    'recorded_at' => $this->now(),
                ])->execute();
            } catch (UniqueConstraintViolationException $exception) {
                throw new ApplicationMasterRekeyConflictException(
                    'The application-master purpose already has immutable verification evidence.',
                    previous: $exception,
                );
            }
            $state = $this->allPurposesVerified($requestId)
                ? ApplicationMasterRekeyState::ReconcileWritersAndWorkers
                : ApplicationMasterRekeyState::VerifyEveryPurpose;
            $now = $this->now();
            $this->advanceRequestProjection($record, $state, $now);
            $this->appendEvent($requestId, 'purpose-verified', [
                'purpose_id' => $purposeId,
                'verified_records' => $verifiedRecords,
                'verification_hash' => $verificationHash,
            ], $now);
            unset($this->recordCache[$requestId]);

            return $this->requireFresh($requestId);
        });
    }

    /**
     * Persist every purpose result for one completed joint adapter atomically.
     *
     * @param array<string, ApplicationMasterPurposeVerification> $results
     */
    public function recordAdapterVerifications(
        string $requestId,
        int $expectedRequestRevision,
        string $adapterId,
        array $results,
    ): ApplicationMasterRekeyRecord {
        $this->ensureSchema();
        try {
            return $this->transactional(function () use (
                $requestId,
                $expectedRequestRevision,
                $adapterId,
                $results,
            ): ApplicationMasterRekeyRecord {
                $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                    ApplicationMasterRekeyState::VerifyEveryPurpose,
                    ApplicationMasterRekeyState::ReconcileWritersAndWorkers,
                ]);
                $progress = $this->requireAdapterFresh($requestId, $adapterId);
                $resultPurposes = array_keys($results);
                sort($resultPurposes, SORT_STRING);
                if ($progress->status !== 'complete'
                    || $progress->unresolvedFailures !== 0
                    || $resultPurposes !== $progress->purposeIds) {
                    throw new ApplicationMasterRekeyConflictException(
                        'Joint-adapter verification does not match its completed purpose roster.',
                    );
                }

                $evidence = [];
                $now = $this->now();
                foreach ($progress->purposeIds as $purposeId) {
                    $result = $results[$purposeId] ?? null;
                    if (!$result instanceof ApplicationMasterPurposeVerification
                        || ($progress->purposeCounts[$purposeId] ?? null) !== $result->verifiedRecords) {
                        throw new ApplicationMasterRekeyConflictException(
                            'Purpose verification does not match its completed adapter projection.',
                        );
                    }
                    $this->database->insert(self::VERIFICATION_TABLE)->values([
                        'request_id' => $requestId,
                        'purpose_id' => $purposeId,
                        'verified_records' => $result->verifiedRecords,
                        'verification_hash' => $result->verificationHash,
                        'recorded_at' => $now,
                    ])->execute();
                    $evidence[$purposeId] = [
                        'verified_records' => $result->verifiedRecords,
                        'verification_hash' => $result->verificationHash,
                    ];
                }
                $state = $this->allPurposesVerified($requestId)
                    ? ApplicationMasterRekeyState::ReconcileWritersAndWorkers
                    : ApplicationMasterRekeyState::VerifyEveryPurpose;
                $this->advanceRequestProjection($record, $state, $now);
                $this->appendEvent($requestId, 'adapter-purposes-verified', [
                    'adapter_id' => $adapterId,
                    'purposes' => $evidence,
                ], $now);
                unset($this->recordCache[$requestId]);

                return $this->requireFresh($requestId);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ApplicationMasterRekeyConflictException(
                'The joint adapter already has immutable purpose verification evidence.',
                previous: $exception,
            );
        }
    }

    public function recordRevocationGate(
        string $requestId,
        int $expectedRequestRevision,
        ApplicationMasterRekeyGate $gate,
        string $evidenceHash,
        int $recordedAt,
    ): ApplicationMasterRekeyRecord {
        self::assertHash($evidenceHash, 'revocation-gate evidence hash');
        $this->ensureSchema();

        return $this->transactional(function () use (
            $requestId,
            $expectedRequestRevision,
            $gate,
            $evidenceHash,
            $recordedAt,
        ): ApplicationMasterRekeyRecord {
            $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                ApplicationMasterRekeyState::ReconcileWritersAndWorkers,
                ApplicationMasterRekeyState::HoldAndOptionallyExecuteRollbackWindow,
                ApplicationMasterRekeyState::ProveBackupRetentionOrRestoreCompatibility,
            ]);
            if (!$this->allPurposesVerified($requestId)) {
                throw new ApplicationMasterRekeyRevocationBlockedException(
                    'Revocation-gate evidence requires complete purpose verification.',
                );
            }
            $recorded = $this->recordedGateIds($requestId);
            $cases = ApplicationMasterRekeyGate::cases();
            $expectedGate = $cases[count($recorded)] ?? null;
            if ($expectedGate !== $gate) {
                throw new ApplicationMasterRekeyConflictException(
                    'Application-master revocation gates must be recorded once in canonical order.',
                );
            }
            $this->database->insert(self::GATE_TABLE)->values([
                'request_id' => $requestId,
                'gate_id' => $gate->value,
                'evidence_hash' => $evidenceHash,
                'recorded_at' => $recordedAt,
            ])->execute();

            $state = match ($gate) {
                ApplicationMasterRekeyGate::WritersAndWorkersReconciled,
                ApplicationMasterRekeyGate::CachesReconciled => ApplicationMasterRekeyState::ReconcileWritersAndWorkers,
                ApplicationMasterRekeyGate::RollbackWindowClosed => ApplicationMasterRekeyState::HoldAndOptionallyExecuteRollbackWindow,
                ApplicationMasterRekeyGate::RetainedBackupsCompatibleOrExpired => ApplicationMasterRekeyState::ProveBackupRetentionOrRestoreCompatibility,
            };
            $this->advanceRequestProjection($record, $state, $recordedAt);
            $this->appendEvent($requestId, 'revocation-gate-recorded', [
                'gate' => $gate->value,
                'evidence_hash' => $evidenceHash,
            ], $recordedAt);
            unset($this->recordCache[$requestId]);

            return $this->requireFresh($requestId);
        });
    }

    public function revokePredecessor(
        string $requestId,
        int $expectedRequestRevision,
        int $revokedAt,
    ): ApplicationMasterRekeyRecord {
        $this->ensureSchema();

        return $this->transactional(function () use (
            $requestId,
            $expectedRequestRevision,
            $revokedAt,
        ): ApplicationMasterRekeyRecord {
            $record = $this->assertRequestRevision($requestId, $expectedRequestRevision, [
                ApplicationMasterRekeyState::InstallNewActiveWithOldLegacyReadVerify,
                ApplicationMasterRekeyState::EnumerateSnapshot,
                ApplicationMasterRekeyState::TransitionBoundedBatches,
                ApplicationMasterRekeyState::VerifyEveryPurpose,
                ApplicationMasterRekeyState::ReconcileWritersAndWorkers,
                ApplicationMasterRekeyState::HoldAndOptionallyExecuteRollbackWindow,
                ApplicationMasterRekeyState::ProveBackupRetentionOrRestoreCompatibility,
            ]);
            $missing = $this->revocationBlockers($record, $revokedAt);
            if ($missing !== []) {
                throw new ApplicationMasterRekeyRevocationBlockedException(
                    'Application-master predecessor revocation is blocked by: ' . implode(', ', $missing) . '.',
                );
            }
            $updated = $this->database->update(self::VERSION_TABLE)->fields([
                'state' => 'revoked',
                'revoked_at' => $revokedAt,
            ])->condition('master_version', $record->fromVersion)
                ->condition('state', 'legacy-read-verify')
                ->execute();
            if ($updated !== 1) {
                throw new ApplicationMasterRekeyConflictException(
                    'The predecessor master state changed before ledger revocation.',
                );
            }
            $this->advanceRequestProjection($record, ApplicationMasterRekeyState::RevokeOldInLedger, $revokedAt);
            $this->appendEvent($requestId, 'predecessor-revoked-in-ledger', [
                'from_version' => $record->fromVersion,
                'to_version' => $record->toVersion,
                'revoked_at' => $revokedAt,
                'external_destruction_claimed' => false,
            ], $revokedAt);
            unset($this->recordCache[$requestId]);

            return $this->requireFresh($requestId);
        });
    }

    public function requireAdapter(string $requestId, string $adapterId): ApplicationMasterAdapterProgress
    {
        $this->ensureSchema();

        return $this->requireAdapterFresh($requestId, $adapterId);
    }

    public function masterVersionState(int $version): string
    {
        $this->ensureSchema();
        $row = $this->fetchOne(
            'SELECT state FROM ' . self::VERSION_TABLE . ' WHERE master_version = :version',
            ['version' => $version],
        );
        if ($row === null) {
            throw new \RuntimeException('The requested application-master version is not recorded.');
        }

        return (string) $row['state'];
    }

    /** @return list<ApplicationMasterRekeyEvent> */
    public function eventChain(string $requestId): array
    {
        $this->ensureSchema();
        $events = [];
        foreach ($this->database->query(
            'SELECT * FROM ' . self::EVENT_TABLE . ' WHERE request_id = :request ORDER BY sequence',
            ['request' => $requestId],
        ) as $row) {
            /** @var array<string, mixed> $row */
            $events[] = new ApplicationMasterRekeyEvent(
                requestId: (string) $row['request_id'],
                sequence: (int) $row['sequence'],
                eventType: (string) $row['event_type'],
                bodyJson: (string) $row['body_json'],
                previousHash: (string) $row['previous_hash'],
                eventHash: (string) $row['event_hash'],
                createdAt: (int) $row['created_at'],
            );
        }

        return $events;
    }

    /**
     * @param list<ApplicationMasterRekeyState> $allowedStates
     * @param \Closure(ApplicationMasterRekeyRecord): array<string, mixed> $sideEffect
     */
    private function mutateRequest(
        string $requestId,
        int $expectedRevision,
        array $allowedStates,
        ApplicationMasterRekeyState $newState,
        string $eventType,
        \Closure $sideEffect,
        ?int $at = null,
    ): ApplicationMasterRekeyRecord {
        $this->ensureSchema();

        return $this->transactional(function () use (
            $requestId,
            $expectedRevision,
            $allowedStates,
            $newState,
            $eventType,
            $sideEffect,
            $at,
        ): ApplicationMasterRekeyRecord {
            $record = $this->assertRequestRevision($requestId, $expectedRevision, $allowedStates);
            $body = $sideEffect($record);
            $timestamp = $at ?? $this->now();
            $this->advanceRequestProjection($record, $newState, $timestamp);
            $this->appendEvent($requestId, $eventType, $body, $timestamp);
            unset($this->recordCache[$requestId]);

            return $this->requireFresh($requestId);
        });
    }

    /** @param list<ApplicationMasterRekeyState> $allowedStates */
    private function assertRequestRevision(
        string $requestId,
        int $expectedRevision,
        array $allowedStates,
    ): ApplicationMasterRekeyRecord {
        $record = $this->requireFresh($requestId);
        if ($record->revision !== $expectedRevision || !in_array($record->state, $allowedStates, true)) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master rekey request revision or state is stale.',
            );
        }

        return $record;
    }

    private function advanceRequestProjection(
        ApplicationMasterRekeyRecord $record,
        ApplicationMasterRekeyState $state,
        int $updatedAt,
    ): void {
        $updated = $this->database->update(self::REQUEST_TABLE)->fields([
            'state' => $state->value,
            'revision' => $record->revision + 1,
            'updated_at' => $updatedAt,
        ])->condition('request_id', $record->requestId)
            ->condition('revision', $record->revision)
            ->condition('state', $record->state->value)
            ->execute();
        if ($updated !== 1) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master request projection changed concurrently.',
            );
        }
    }

    /** @param list<string> $purposeIds */
    private function assertAdapterPurposes(string $requestId, string $adapterId, array $purposeIds): void
    {
        $this->ensureSchema();
        $expected = [];
        foreach ($this->database->query(
            'SELECT purpose_id, canonical_policy_json FROM ' . self::PURPOSE_TABLE . ' WHERE request_id = :request',
            ['request' => $requestId],
        ) as $row) {
            $policy = json_decode((string) $row['canonical_policy_json'], true, 16, JSON_THROW_ON_ERROR);
            if (is_array($policy) && ($policy['adapter_id'] ?? null) === $adapterId) {
                $expected[] = (string) $row['purpose_id'];
            }
        }
        sort($expected, SORT_STRING);
        if ($expected === [] || $expected !== $purposeIds) {
            throw new ApplicationMasterRekeyConflictException(
                'The adapter purpose roster does not match the immutable registry snapshot.',
            );
        }
    }

    /** @param array<string, mixed> $deltas */
    private function assertPurposeDeltas(ApplicationMasterAdapterProgress $progress, array $deltas): void
    {
        ksort($deltas, SORT_STRING);
        if (array_keys($deltas) !== $progress->purposeIds) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master batch purpose counts do not match its joint adapter.',
            );
        }
        foreach ($deltas as $delta) {
            if (!is_int($delta) || $delta < 0) {
                throw new \InvalidArgumentException('Application-master purpose count deltas must be non-negative integers.');
            }
        }
    }

    private function adapterForPurpose(string $requestId, string $purposeId): ApplicationMasterAdapterProgress
    {
        $purpose = $this->fetchOne(
            'SELECT canonical_policy_json FROM ' . self::PURPOSE_TABLE . ' WHERE request_id = :request AND purpose_id = :purpose',
            ['request' => $requestId, 'purpose' => $purposeId],
        );
        if ($purpose === null) {
            throw new ApplicationMasterRekeyConflictException('The verified purpose is not in the immutable snapshot.');
        }
        $policy = json_decode((string) $purpose['canonical_policy_json'], true, 16, JSON_THROW_ON_ERROR);
        $adapterId = is_array($policy) ? ($policy['adapter_id'] ?? null) : null;
        if (!is_string($adapterId)) {
            throw new \RuntimeException('The purpose snapshot has invalid adapter metadata.');
        }

        return $this->requireAdapterFresh($requestId, $adapterId);
    }

    private function allAdaptersComplete(string $requestId): bool
    {
        $total = count($this->expectedAdapterIds($requestId));
        $recorded = $this->countRows(self::ADAPTER_TABLE, $requestId);
        $complete = $this->countRows(self::ADAPTER_TABLE, $requestId, 'status', 'complete');

        return $total > 0 && $total === $recorded && $total === $complete;
    }

    /** @return list<string> */
    private function expectedAdapterIds(string $requestId): array
    {
        $ids = [];
        foreach ($this->database->query(
            'SELECT canonical_policy_json FROM ' . self::PURPOSE_TABLE . ' WHERE request_id = :request',
            ['request' => $requestId],
        ) as $row) {
            $policy = json_decode((string) $row['canonical_policy_json'], true, 16, JSON_THROW_ON_ERROR);
            $adapterId = is_array($policy) ? ($policy['adapter_id'] ?? null) : null;
            if (!is_string($adapterId)) {
                throw new \RuntimeException('The purpose snapshot has invalid adapter metadata.');
            }
            $ids[$adapterId] = true;
        }
        $result = array_keys($ids);
        sort($result, SORT_STRING);

        return $result;
    }

    private function allPurposesVerified(string $requestId): bool
    {
        return $this->countRows(self::PURPOSE_TABLE, $requestId) > 0
            && $this->countRows(self::PURPOSE_TABLE, $requestId)
                === $this->countRows(self::VERIFICATION_TABLE, $requestId);
    }

    /** @return list<string> */
    private function recordedGateIds(string $requestId): array
    {
        $present = [];
        foreach ($this->database->query(
            'SELECT gate_id FROM ' . self::GATE_TABLE . ' WHERE request_id = :request',
            ['request' => $requestId],
        ) as $row) {
            $present[(string) $row['gate_id']] = true;
        }

        return array_values(array_filter(
            array_map(
                static fn(ApplicationMasterRekeyGate $gate): string => $gate->value,
                ApplicationMasterRekeyGate::cases(),
            ),
            static fn(string $gate): bool => isset($present[$gate]),
        ));
    }

    /** @return list<string> */
    private function revocationBlockers(ApplicationMasterRekeyRecord $record, int $revokedAt): array
    {
        $blockers = [];
        if (!$this->allAdaptersComplete($record->requestId)) {
            $blockers[] = 'adapter transition';
        }
        if (!$this->allPurposesVerified($record->requestId)) {
            $blockers[] = 'purpose verification';
        }
        if ($this->recordedGateIds($record->requestId) !== array_map(
            static fn(ApplicationMasterRekeyGate $gate): string => $gate->value,
            ApplicationMasterRekeyGate::cases(),
        )) {
            $blockers[] = 'revocation gates';
        }
        if ($record->unresolvedFailures !== 0) {
            $blockers[] = 'unresolved failures';
        }
        if ($revokedAt < $record->retentionDeadline) {
            $blockers[] = 'backup/key retention deadline';
        }

        return $blockers;
    }

    private function countRows(
        string $table,
        string $requestId,
        ?string $field = null,
        ?string $value = null,
    ): int {
        $sql = 'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE request_id = :request';
        $arguments = ['request' => $requestId];
        if ($field !== null) {
            $sql .= ' AND ' . $field . ' = :value';
            $arguments['value'] = $value;
        }
        $row = $this->fetchOne($sql, $arguments);

        return (int) ($row['total'] ?? 0);
    }

    /** @param array<string, mixed> $body */
    private function appendEvent(
        string $requestId,
        string $eventType,
        array $body,
        ?int $createdAt = null,
    ): void {
        $last = $this->fetchOne(
            'SELECT sequence, event_hash FROM ' . self::EVENT_TABLE . ' WHERE request_id = :request ORDER BY sequence DESC LIMIT 1',
            ['request' => $requestId],
        );
        $sequence = $last === null ? 1 : (int) $last['sequence'] + 1;
        $previousHash = $last === null ? self::ZERO_HASH : (string) $last['event_hash'];
        $bodyJson = $this->canonicalJson($body);
        $timestamp = $createdAt ?? $this->now();
        $eventHash = ApplicationMasterRekeyEvent::computeHash(
            $requestId,
            $sequence,
            $eventType,
            $bodyJson,
            $previousHash,
            $timestamp,
        );
        $this->database->insert(self::EVENT_TABLE)->values([
            'request_id' => $requestId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'body_json' => $bodyJson,
            'previous_hash' => $previousHash,
            'event_hash' => $eventHash,
            'created_at' => $timestamp,
        ])->execute();
    }

    private function requireFresh(string $requestId): ApplicationMasterRekeyRecord
    {
        $record = $this->findRecord($requestId);

        return $record ?? throw new \RuntimeException('The application-master rekey request does not exist.');
    }

    private function findRecord(string $requestId): ?ApplicationMasterRekeyRecord
    {
        $row = $this->fetchOne(
            'SELECT * FROM ' . self::REQUEST_TABLE . ' WHERE request_id = :request',
            ['request' => $requestId],
        );
        if ($row === null) {
            return null;
        }

        return new ApplicationMasterRekeyRecord(
            requestId: (string) $row['request_id'],
            requestDigest: (string) $row['request_digest'],
            fromVersion: (int) $row['from_version'],
            toVersion: (int) $row['to_version'],
            registryChecksum: (string) $row['registry_checksum'],
            authorizationDigest: (string) $row['authorization_digest'],
            actor: (string) $row['actor'],
            rollbackDeadline: (int) $row['rollback_deadline'],
            retentionDeadline: (int) $row['retention_deadline'],
            state: ApplicationMasterRekeyState::from((string) $row['state']),
            revision: (int) $row['revision'],
            unresolvedFailures: (int) $row['unresolved_failures'],
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at'],
        );
    }

    private function requireAdapterFresh(string $requestId, string $adapterId): ApplicationMasterAdapterProgress
    {
        $row = $this->fetchOne(
            'SELECT * FROM ' . self::ADAPTER_TABLE . ' WHERE request_id = :request AND adapter_id = :adapter',
            ['request' => $requestId, 'adapter' => $adapterId],
        );
        if ($row === null) {
            throw new \RuntimeException('The application-master rekey adapter snapshot does not exist.');
        }
        $purposeIds = json_decode((string) $row['purpose_ids_json'], true, 32, JSON_THROW_ON_ERROR);
        $purposeCounts = json_decode((string) $row['purpose_counts_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($purposeIds) || !is_array($purposeCounts)) {
            throw new \RuntimeException('The application-master adapter projection is malformed.');
        }

        return new ApplicationMasterAdapterProgress(
            requestId: (string) $row['request_id'],
            adapterId: (string) $row['adapter_id'],
            purposeIds: array_values(array_map('strval', $purposeIds)),
            snapshotToken: (string) $row['snapshot_token'],
            totalRecords: (int) $row['total_records'],
            cursor: $row['cursor'] === null ? null : (string) $row['cursor'],
            transitionedRecords: (int) $row['transitioned_records'],
            purposeCounts: array_map('intval', $purposeCounts),
            batchChainHash: (string) $row['batch_chain_hash'],
            status: (string) $row['status'],
            revision: (int) $row['revision'],
            unresolvedFailures: (int) $row['unresolved_failures'],
        );
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed>|null */
    private function fetchOne(string $sql, array $arguments): ?array
    {
        foreach ($this->database->query($sql, $arguments) as $row) {
            /** @var array<string, mixed> $row */
            return $row;
        }

        return null;
    }

    private function ensureSchema(): void
    {
        if ($this->schemaVerified) {
            return;
        }
        $requirements = [
            self::REQUEST_TABLE => [
                'request_id', 'request_digest', 'from_version', 'to_version', 'registry_checksum',
                'authorization_digest', 'actor', 'rollback_deadline', 'retention_deadline', 'state', 'revision',
                'unresolved_failures', 'created_at', 'updated_at',
            ],
            self::VERSION_TABLE => [
                'master_version', 'state', 'reference_fingerprint', 'activated_at', 'retained_until', 'revoked_at',
            ],
            self::PURPOSE_TABLE => [
                'request_id', 'purpose_id', 'canonical_policy_json', 'policy_checksum',
            ],
            self::ADAPTER_TABLE => [
                'request_id', 'adapter_id', 'purpose_ids_json', 'snapshot_token', 'total_records',
                'cursor', 'transitioned_records', 'purpose_counts_json', 'batch_chain_hash',
                'status', 'revision', 'unresolved_failures',
            ],
            self::FAILURE_TABLE => [
                'failure_id', 'request_id', 'adapter_id', 'cursor', 'failure_code',
                'evidence_hash', 'opened_at', 'resolved_at', 'resolution_hash',
            ],
            self::VERIFICATION_TABLE => [
                'request_id', 'purpose_id', 'verified_records', 'verification_hash', 'recorded_at',
            ],
            self::GATE_TABLE => ['request_id', 'gate_id', 'evidence_hash', 'recorded_at'],
            self::EVENT_TABLE => [
                'event_id', 'request_id', 'sequence', 'event_type', 'body_json',
                'previous_hash', 'event_hash', 'created_at',
            ],
        ];
        foreach ($requirements as $table => $fields) {
            SchemaRequirement::assertAvailable($this->database, $table, $fields, self::MIGRATION_ID);
        }
        $this->schemaVerified = true;
    }

    private function now(): int
    {
        return ($this->clock ?? static fn(): int => time())();
    }

    /** @param array<array-key, mixed> $value */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<array-key, mixed> */
    private function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private static function assertHash(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Application-master %s must be a lowercase SHA-256 digest.', $label));
        }
    }

    private static function assertStableIdentifier(string $value, string $label): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $value)) {
            throw new \InvalidArgumentException(sprintf('Application-master %s must be a stable lowercase identifier.', $label));
        }
    }

    private function updateAdapterFailureCount(ApplicationMasterAdapterProgress $progress, int $delta): void
    {
        $next = $progress->unresolvedFailures + $delta;
        if ($next < 0) {
            throw new ApplicationMasterRekeyConflictException('Application-master adapter failure count underflow.');
        }
        $updated = $this->database->update(self::ADAPTER_TABLE)->fields([
            'unresolved_failures' => $next,
            'revision' => $progress->revision + 1,
        ])->condition('request_id', $progress->requestId)
            ->condition('adapter_id', $progress->adapterId)
            ->condition('revision', $progress->revision)
            ->condition('unresolved_failures', $progress->unresolvedFailures)
            ->execute();
        if ($updated !== 1) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master adapter failure count changed concurrently.',
            );
        }
    }

    private function updateRequestFailureCount(ApplicationMasterRekeyRecord $record, int $delta, int $updatedAt): void
    {
        $next = $record->unresolvedFailures + $delta;
        if ($next < 0) {
            throw new ApplicationMasterRekeyConflictException('Application-master request failure count underflow.');
        }
        $updated = $this->database->update(self::REQUEST_TABLE)->fields([
            'unresolved_failures' => $next,
            'revision' => $record->revision + 1,
            'updated_at' => $updatedAt,
        ])->condition('request_id', $record->requestId)
            ->condition('revision', $record->revision)
            ->condition('state', $record->state->value)
            ->condition('unresolved_failures', $record->unresolvedFailures)
            ->execute();
        if ($updated !== 1) {
            throw new ApplicationMasterRekeyConflictException(
                'The application-master request failure count changed concurrently.',
            );
        }
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function transactional(\Closure $operation): mixed
    {
        $transaction = $this->database->transaction('application-master-rekey');
        try {
            $result = $operation();
            $transaction->commit();

            return $result;
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }
}

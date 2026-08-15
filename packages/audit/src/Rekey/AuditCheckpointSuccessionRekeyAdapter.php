<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Rekey;

use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\Audit\Integrity\AuditCheckpointCustody;
use Waaseyaa\Audit\Integrity\AuditCheckpointSuccessionVerifier;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;

/** Transactional cryptographic succession for retained audit checkpoint history. @api */
final readonly class AuditCheckpointSuccessionRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    public const string ID = 'audit-checkpoint-succession-v1';

    private const string FORMAT = 'waaseyaa.audit.checkpoint-succession.v1';
    private const string ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private DatabaseInterface $database) {}

    public function databaseAuthority(): DatabaseInterface
    {
        return $this->database;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function purposeIds(): array
    {
        return [ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC];
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        $this->assertAuthority($context);
        if ($this->record($context->request->requestDigest, 'succession-anchor') !== null) {
            throw new ApplicationMasterRekeyConflictException('The audit succession request already has an anchor.');
        }
        $capture = $this->capture($context, requirePredecessorOnly: true);

        return new ApplicationMasterInventorySnapshot(
            $this->snapshotToken($context, 'forward', $capture),
            1,
        );
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        $this->assertAuthority($context);
        if ($cursor !== null || $limit < 1 || $snapshot->totalRecords !== 1) {
            throw new ApplicationMasterRekeyConflictException('Audit succession requires one unprocessed logical anchor record.');
        }
        if ($context->keyring->activeVersion() !== $context->request->toVersion) {
            throw new ApplicationMasterRekeyConflictException('Audit succession signing requires the request successor as active version.');
        }
        $capture = $this->capture($context, requirePredecessorOnly: true);
        if (!hash_equals($snapshot->token, $this->snapshotToken($context, 'forward', $capture))) {
            throw new ApplicationMasterRekeyConflictException('The audit succession snapshot changed before anchor creation.');
        }

        $record = $this->appendRecord($context, 'succession-anchor', null, $capture);

        return new ApplicationMasterBatchResult(
            nextCursor: 'anchor:' . $record['sequence'],
            transitionedRecords: 1,
            purposeCountDeltas: [ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC => 1],
            batchCommitment: hash('sha256', self::json([
                'format' => 'waaseyaa.audit.succession-batch.v1',
                'request_digest' => $context->request->requestDigest,
                'chain_digest' => $capture['chain_digest'],
                'pruned_checkpoint_digest' => $capture['pruned_checkpoint_digest'],
                'record_hash' => $record['record_hash'],
            ])),
        );
    }

    public function verify(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        $this->assertAuthority($context);
        if ($snapshot->totalRecords !== 1) {
            throw new ApplicationMasterRekeyConflictException('The audit succession verification snapshot is malformed.');
        }
        $capture = $this->capture($context, requirePredecessorOnly: true);
        $record = $this->record($context->request->requestDigest, 'succession-anchor');
        if ($record === null || !$this->verifyRecord($context, $record, $capture)) {
            throw new ApplicationMasterRekeyConflictException('The audit succession anchor did not verify.');
        }

        return [ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC => new ApplicationMasterPurposeVerification(
            1,
            hash('sha256', self::json([
                'format' => 'waaseyaa.audit.succession-verification.v1',
                'request_digest' => $context->request->requestDigest,
                'snapshot_token' => $snapshot->token,
                'record_hash' => (string) $record['record_hash'],
            ])),
        )];
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        $this->assertAuthority($context);
        $capture = $this->capture($context, requirePredecessorOnly: false);
        $anchor = $this->record($context->request->requestDigest, 'succession-anchor');
        if ($anchor === null || !$this->verifyRecord($context, $anchor, null)) {
            throw new ApplicationMasterRekeyConflictException('Audit rollback requires the verified forward succession anchor.');
        }

        return new ApplicationMasterInventorySnapshot(
            $this->snapshotToken($context, 'rollback', $capture),
            1,
        );
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        $this->assertAuthority($context);
        if ($cursor !== null || $limit < 1 || $snapshot->totalRecords !== 1) {
            throw new ApplicationMasterRekeyConflictException('Audit succession rollback requires one unprocessed marker.');
        }
        if ($context->keyring->activeVersion() !== $context->request->fromVersion) {
            throw new ApplicationMasterRekeyConflictException('Audit rollback signing requires the restored predecessor as active version.');
        }
        $capture = $this->capture($context, requirePredecessorOnly: false);
        if (!hash_equals($snapshot->token, $this->snapshotToken($context, 'rollback', $capture))) {
            throw new ApplicationMasterRekeyConflictException('The audit rollback snapshot changed before marker creation.');
        }
        $anchor = $this->record($context->request->requestDigest, 'succession-anchor');
        if ($anchor === null) {
            throw new ApplicationMasterRekeyConflictException('Audit rollback has no forward anchor.');
        }
        $record = $this->appendRecord($context, 'succession-rollback', (int) $anchor['sequence'], $capture);

        return new ApplicationMasterBatchResult(
            nextCursor: 'rollback:' . $record['sequence'],
            transitionedRecords: 1,
            purposeCountDeltas: [ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC => 1],
            batchCommitment: hash('sha256', $record['record_hash']),
        );
    }

    public function verifyRollback(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        $this->assertAuthority($context);
        $record = $this->record($context->request->requestDigest, 'succession-rollback');
        if ($record === null || !$this->verifyRecord($context, $record, null)) {
            throw new ApplicationMasterRekeyConflictException('The audit succession rollback marker did not verify.');
        }

        return [ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC => new ApplicationMasterPurposeVerification(
            1,
            hash('sha256', self::json([
                'format' => 'waaseyaa.audit.succession-rollback-verification.v1',
                'request_digest' => $context->request->requestDigest,
                'snapshot_token' => $snapshot->token,
                'record_hash' => (string) $record['record_hash'],
            ])),
        )];
    }

    private function assertAuthority(ApplicationMasterRekeyContext $context): void
    {
        if ($context->database !== $this->database) {
            throw new ApplicationMasterRekeyConflictException(
                'The audit succession adapter requires its exact coordinator database authority.',
            );
        }
    }

    /**
     * @return array{
     *   checkpoints: list<array<string, mixed>>,
     *   pruned: list<array{checkpoint_id: int, checkpoint_uuid: string, checkpoint_hash: string}>,
     *   chain_digest: string, pruned_checkpoint_digest: string, checkpoint_count: int,
     *   pruned_checkpoint_count: int, terminal_checkpoint_id: int,
     *   terminal_checkpoint_uuid: string, terminal_segment_end_id: int,
     *   terminal_checkpoint_hash: string, previous_ledger_hash: string
     * }
     */
    private function capture(ApplicationMasterRekeyContext $context, bool $requirePredecessorOnly): array
    {
        $custody = new AuditCheckpointCustody($context->keyring);
        $verification = new AuditChainVerifier($this->database, custody: $custody)->verify();
        if (!$verification->ok) {
            throw new ApplicationMasterRekeyConflictException(
                'Audit predecessor history and prune evidence must verify before succession.',
            );
        }
        $checkpoints = iterator_to_array(
            $this->database->select('audit_checkpoint')->fields('audit_checkpoint', [
                'id', 'uuid', 'segment_start_id', 'segment_end_id', 'row_count', 'segment_hash',
                'prev_checkpoint_hash', 'checkpoint_hash', 'signature', 'hash_version',
                'is_genesis', 'pruned', 'prune_authorization', 'created_at',
            ])->orderBy('id', 'ASC')->execute(),
            false,
        );
        $chainDigest = hash('sha256', 'waaseyaa.audit.checkpoint-succession-chain.v1');
        $prunedDigest = hash('sha256', 'waaseyaa.audit.checkpoint-succession-pruned.v1');
        $pruned = [];
        $window = new AuditCheckpointSuccessionVerifier($this->database, $custody)->window($checkpoints);
        foreach ($checkpoints as $checkpoint) {
            $version = $custody->checkpointEnvelopeVersion((string) $checkpoint['signature']);
            if ($requirePredecessorOnly
                && (int) $checkpoint['id'] > $window->pinnedThroughCheckpointId
                && $version !== $context->request->fromVersion) {
                throw new ApplicationMasterRekeyConflictException(
                    'Audit predecessor history must be wholly version-bound to the request predecessor.',
                );
            }
            $chainDigest = hash('sha256', $chainDigest . "\x1f" . self::checkpointRecord($checkpoint));
            if ((bool) $checkpoint['pruned']) {
                $evidence = [
                    'checkpoint_id' => (int) $checkpoint['id'],
                    'checkpoint_uuid' => (string) $checkpoint['uuid'],
                    'checkpoint_hash' => (string) $checkpoint['checkpoint_hash'],
                ];
                $pruned[] = $evidence;
                $prunedDigest = hash('sha256', $prunedDigest . "\x1f" . self::json($evidence));
            }
        }
        $terminal = $checkpoints === [] ? null : $checkpoints[array_key_last($checkpoints)];
        $head = $this->latestRecord();

        return [
            'checkpoints' => $checkpoints,
            'pruned' => $pruned,
            'chain_digest' => $chainDigest,
            'pruned_checkpoint_digest' => $prunedDigest,
            'checkpoint_count' => count($checkpoints),
            'pruned_checkpoint_count' => count($pruned),
            'terminal_checkpoint_id' => $terminal === null ? 0 : (int) $terminal['id'],
            'terminal_checkpoint_uuid' => $terminal === null ? '' : (string) $terminal['uuid'],
            'terminal_segment_end_id' => $terminal === null ? 0 : (int) $terminal['segment_end_id'],
            'terminal_checkpoint_hash' => $terminal === null ? self::ZERO_HASH : (string) $terminal['checkpoint_hash'],
            'previous_ledger_hash' => $head === null ? self::ZERO_HASH : (string) $head['record_hash'],
        ];
    }

    /** @param array<string, mixed> $capture */
    private function snapshotToken(ApplicationMasterRekeyContext $context, string $direction, array $capture): string
    {
        return hash('sha256', self::json([
            'format' => 'waaseyaa.audit.succession-snapshot.v1',
            'request_digest' => $context->request->requestDigest,
            'direction' => $direction,
            'from_master_version' => $context->request->fromVersion,
            'to_master_version' => $context->request->toVersion,
            'chain_digest' => $capture['chain_digest'],
            'pruned_checkpoint_digest' => $capture['pruned_checkpoint_digest'],
            'checkpoint_count' => $capture['checkpoint_count'],
            'pruned_checkpoint_count' => $capture['pruned_checkpoint_count'],
            'terminal_checkpoint_id' => $capture['terminal_checkpoint_id'],
            'previous_ledger_hash' => $capture['previous_ledger_hash'],
        ]));
    }

    /**
     * @param array<string, mixed> $capture
     * @return array{sequence: int, record_hash: string}
     */
    private function appendRecord(
        ApplicationMasterRekeyContext $context,
        string $recordKind,
        ?int $rolledBackSequence,
        array $capture,
    ): array {
        $head = $this->latestRecord();
        $sequence = $head === null ? 1 : (int) $head['sequence'] + 1;
        $createdAt = time();
        $body = $this->body(
            $context,
            $recordKind,
            $rolledBackSequence,
            $sequence,
            $createdAt,
            $capture,
        );
        $canonicalBody = self::json($body);
        $signature = new AuditCheckpointCustody($context->keyring)->sealSuccessionAnchor($canonicalBody);
        $recordHash = hash('sha256', implode("\0", [
            'waaseyaa.audit.checkpoint-succession-ledger.v1',
            (string) $sequence,
            $recordKind,
            $canonicalBody,
            $signature,
            (string) $capture['previous_ledger_hash'],
            (string) $createdAt,
        ]));
        $values = $body;
        unset($values['purpose']);
        $values['purpose_id'] = $body['purpose'];
        $values['signature'] = $signature;
        $values['record_hash'] = $recordHash;
        $this->database->insert('audit_checkpoint_succession')->values($values)->execute();
        foreach ($capture['pruned'] as $evidence) {
            $this->database->insert('audit_checkpoint_succession_pruned')->values([
                'anchor_sequence' => $sequence,
                ...$evidence,
            ])->execute();
        }

        return ['sequence' => $sequence, 'record_hash' => $recordHash];
    }

    /** @param array<string, mixed> $capture */
    private function body(
        ApplicationMasterRekeyContext $context,
        string $recordKind,
        ?int $rolledBackSequence,
        int $sequence,
        int $createdAt,
        array $capture,
    ): array {
        return [
            'record_kind' => $recordKind,
            'format' => self::FORMAT,
            'purpose' => ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            'algorithm' => 'hmac-sha256',
            'from_master_version' => $context->request->fromVersion,
            'to_master_version' => $context->request->toVersion,
            'predecessor_key_id' => $context->keyring->referenceFingerprint($context->request->fromVersion),
            'successor_key_id' => $context->keyring->referenceFingerprint($context->request->toVersion),
            'chain_digest' => $capture['chain_digest'],
            'pruned_checkpoint_digest' => $capture['pruned_checkpoint_digest'],
            'pruned_checkpoint_count' => $capture['pruned_checkpoint_count'],
            'checkpoint_count' => $capture['checkpoint_count'],
            'terminal_checkpoint_id' => $capture['terminal_checkpoint_id'],
            'terminal_checkpoint_uuid' => $capture['terminal_checkpoint_uuid'],
            'terminal_segment_end_id' => $capture['terminal_segment_end_id'],
            'terminal_checkpoint_hash' => $capture['terminal_checkpoint_hash'],
            'rekey_request_digest' => $context->request->requestDigest,
            'registry_checksum' => $context->request->registryChecksum,
            'rolled_back_sequence' => $rolledBackSequence,
            'previous_ledger_hash' => $capture['previous_ledger_hash'],
            'sequence' => $sequence,
            'created_at' => $createdAt,
        ];
    }

    /** @param array<string, mixed> $record @param array<string, mixed>|null $capture */
    private function verifyRecord(ApplicationMasterRekeyContext $context, array $record, ?array $capture): bool
    {
        $body = self::bodyFromRecord($record);
        if ($capture !== null
            && ((string) $record['chain_digest'] !== $capture['chain_digest']
                || (string) $record['pruned_checkpoint_digest'] !== $capture['pruned_checkpoint_digest']
                || (int) $record['pruned_checkpoint_count'] !== $capture['pruned_checkpoint_count'])) {
            return false;
        }
        $canonicalBody = self::json($body);
        $custody = new AuditCheckpointCustody($context->keyring);
        if (!$custody->verifySuccessionAnchor((string) $record['signature'], $canonicalBody)) {
            return false;
        }
        $expectedHash = hash('sha256', implode("\0", [
            'waaseyaa.audit.checkpoint-succession-ledger.v1',
            (string) $record['sequence'],
            (string) $record['record_kind'],
            $canonicalBody,
            (string) $record['signature'],
            (string) $record['previous_ledger_hash'],
            (string) $record['created_at'],
        ]));

        return hash_equals($expectedHash, (string) $record['record_hash'])
            && $this->verifyPrunedChildren($record);
    }

    /** @param array<string, mixed> $record */
    private function verifyPrunedChildren(array $record): bool
    {
        $rows = iterator_to_array(
            $this->database->select('audit_checkpoint_succession_pruned')
                ->fields('audit_checkpoint_succession_pruned', [
                    'checkpoint_id', 'checkpoint_uuid', 'checkpoint_hash',
                ])->condition('anchor_sequence', (int) $record['sequence'])
                ->orderBy('checkpoint_id', 'ASC')->execute(),
            false,
        );
        $digest = hash('sha256', 'waaseyaa.audit.checkpoint-succession-pruned.v1');
        foreach ($rows as $row) {
            $digest = hash('sha256', $digest . "\x1f" . self::json([
                'checkpoint_id' => (int) $row['checkpoint_id'],
                'checkpoint_uuid' => (string) $row['checkpoint_uuid'],
                'checkpoint_hash' => (string) $row['checkpoint_hash'],
            ]));
        }

        return count($rows) === (int) $record['pruned_checkpoint_count']
            && hash_equals((string) $record['pruned_checkpoint_digest'], $digest);
    }

    /** @return array<string, mixed>|null */
    private function record(string $requestDigest, string $kind): ?array
    {
        $rows = iterator_to_array(
            $this->database->select('audit_checkpoint_succession')
                ->fields('audit_checkpoint_succession')
                ->condition('rekey_request_digest', $requestDigest)
                ->condition('record_kind', $kind)
                ->range(0, 1)->execute(),
            false,
        );

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function latestRecord(): ?array
    {
        $rows = iterator_to_array(
            $this->database->select('audit_checkpoint_succession')
                ->fields('audit_checkpoint_succession')
                ->orderBy('sequence', 'DESC')->range(0, 1)->execute(),
            false,
        );

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $checkpoint */
    private static function checkpointRecord(array $checkpoint): string
    {
        return self::json([
            'id' => (int) $checkpoint['id'],
            'uuid' => (string) $checkpoint['uuid'],
            'segment_start_id' => (int) $checkpoint['segment_start_id'],
            'segment_end_id' => (int) $checkpoint['segment_end_id'],
            'row_count' => (int) $checkpoint['row_count'],
            'segment_hash' => (string) $checkpoint['segment_hash'],
            'prev_checkpoint_hash' => (string) $checkpoint['prev_checkpoint_hash'],
            'checkpoint_hash' => (string) $checkpoint['checkpoint_hash'],
            'signature' => (string) $checkpoint['signature'],
            'hash_version' => (string) $checkpoint['hash_version'],
            'is_genesis' => (int) (bool) $checkpoint['is_genesis'],
            'created_at' => (string) $checkpoint['created_at'],
        ]);
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private static function bodyFromRecord(array $record): array
    {
        return [
            'record_kind' => (string) $record['record_kind'],
            'format' => (string) $record['format'],
            'purpose' => (string) $record['purpose_id'],
            'algorithm' => (string) $record['algorithm'],
            'from_master_version' => (int) $record['from_master_version'],
            'to_master_version' => (int) $record['to_master_version'],
            'predecessor_key_id' => (string) $record['predecessor_key_id'],
            'successor_key_id' => (string) $record['successor_key_id'],
            'chain_digest' => (string) $record['chain_digest'],
            'pruned_checkpoint_digest' => (string) $record['pruned_checkpoint_digest'],
            'pruned_checkpoint_count' => (int) $record['pruned_checkpoint_count'],
            'checkpoint_count' => (int) $record['checkpoint_count'],
            'terminal_checkpoint_id' => (int) $record['terminal_checkpoint_id'],
            'terminal_checkpoint_uuid' => (string) $record['terminal_checkpoint_uuid'],
            'terminal_segment_end_id' => (int) $record['terminal_segment_end_id'],
            'terminal_checkpoint_hash' => (string) $record['terminal_checkpoint_hash'],
            'rekey_request_digest' => (string) $record['rekey_request_digest'],
            'registry_checksum' => (string) $record['registry_checksum'],
            'rolled_back_sequence' => $record['rolled_back_sequence'] === null
                ? null
                : (int) $record['rolled_back_sequence'],
            'previous_ledger_hash' => (string) $record['previous_ledger_hash'],
            'sequence' => (int) $record['sequence'],
            'created_at' => (int) $record['created_at'],
        ];
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

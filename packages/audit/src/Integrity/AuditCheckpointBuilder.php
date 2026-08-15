<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Seals the next segment of unsealed audit_event rows into an audit_checkpoint.
 *
 * The builder:
 *  1. Finds the latest checkpoint (segment_end_id = high-water mark).
 *  2. Selects all audit_event rows with id > high-water mark, ordered ASC.
 *  3. Walks the rows computing a chained row_hash via AuditEventCanonicalizer and
 *     UPDATE-ing each row in place.
 *  4. Computes the checkpoint_hash from the segment bounds, row count, final
 *     row hash (= segment_hash), and the previous checkpoint's hash.
 *  5. INSERTs the new audit_checkpoint row and exports it via CheckpointSink.
 *
 * Returns null when there are no unsealed events.
 *
 * @api
 */
final class AuditCheckpointBuilder
{
    private readonly LoggerInterface $logger;
    private readonly ?AuditCheckpointCustody $custody;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly CheckpointSink $sink,
        ?LoggerInterface $logger = null,
        #[\SensitiveParameter]
        ?string $hmacKey = null,
        ?AuditCheckpointCustody $custody = null,
    ) {
        if ($custody !== null && $hmacKey !== null && $hmacKey !== '') {
            throw new \InvalidArgumentException('Supply composed audit custody or a legacy HMAC key, not both.');
        }
        $this->logger = $logger ?? new NullLogger();
        $this->custody = $custody ?? ($hmacKey === '' || $hmacKey === null
            ? null
            : new AuditCheckpointCustody(legacyKey: $hmacKey));
    }

    public function build(): ?AuditCheckpoint
    {
        // ----------------------------------------------------------------
        // Step 1: find the latest sealed checkpoint.
        // ----------------------------------------------------------------
        $latestRows = iterator_to_array(
            $this->database
                ->select('audit_checkpoint')
                ->fields('audit_checkpoint', [
                    'id', 'segment_end_id', 'segment_hash', 'checkpoint_hash',
                    'signature', 'is_genesis',
                ])
                ->orderBy('segment_end_id', 'DESC')
                ->range(0, 1)
                ->execute(),
            false,
        );

        if ($latestRows !== []) {
            $latest = $latestRows[0];
            $latest['signature'] = $this->authenticatePristineGenesis($latest);
            $this->assertActiveSigningAuthorized($latest);
            $lastSealedId       = (int) $latest['segment_end_id'];
            $prevRowHash        = (string) $latest['segment_hash'];
            $prevCheckpointHash = (string) $latest['checkpoint_hash'];
        } else {
            $lastSealedId       = 0;
            $prevRowHash        = AuditEventCanonicalizer::GENESIS_HASH;
            $prevCheckpointHash = AuditEventCanonicalizer::GENESIS_HASH;
        }

        // ----------------------------------------------------------------
        // Step 2: select unsealed rows.
        // ----------------------------------------------------------------
        $rows = iterator_to_array(
            $this->database
                ->select('audit_event')
                ->fields('audit_event', [
                    'id', 'uuid', 'event_kind', 'account_uid', 'actor_uid',
                    'entity_type_id', 'entity_uuid', 'subject_uri', 'outcome',
                    'severity', 'attributes', 'created_at',
                ])
                ->condition('id', $lastSealedId, '>')
                ->orderBy('id', 'ASC')
                ->execute(),
            false,
        );

        if ($rows === []) {
            return null;
        }

        // ----------------------------------------------------------------
        // Step 3: walk rows building the row hash chain.
        // ----------------------------------------------------------------
        $firstRowId = (int) $rows[0]['id'];
        $lastRowId  = 0;
        $count      = 0;

        foreach ($rows as $row) {
            $rowId   = (int) $row['id'];
            $rowHash = AuditEventCanonicalizer::rowHash($row, $prevRowHash);

            $this->database
                ->update('audit_event')
                ->fields(['row_hash' => $rowHash, 'prev_hash' => $prevRowHash])
                ->condition('id', $rowId)
                ->execute();

            $prevRowHash = $rowHash;
            $lastRowId   = $rowId;
            ++$count;
        }

        // ----------------------------------------------------------------
        // Step 4: compute the segment hash (= last row_hash in the chain).
        // ----------------------------------------------------------------
        $segmentHash = $prevRowHash;

        // ----------------------------------------------------------------
        // Step 5: compute the checkpoint hash.
        // ----------------------------------------------------------------
        $checkpointHash = AuditCheckpointHasher::checkpointHash(
            $firstRowId,
            $lastRowId,
            $count,
            $segmentHash,
            $prevCheckpointHash,
        );

        // ----------------------------------------------------------------
        // Step 6: optional HMAC signature.
        // ----------------------------------------------------------------
        $signature = $this->custody?->sealCheckpoint($checkpointHash) ?? '';

        // ----------------------------------------------------------------
        // Step 7: INSERT the checkpoint row.
        // ----------------------------------------------------------------
        $uuid      = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $createdAt = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $this->database->insert('audit_checkpoint')->values([
            'uuid'                 => $uuid,
            'segment_start_id'     => $firstRowId,
            'segment_end_id'       => $lastRowId,
            'row_count'            => $count,
            'segment_hash'         => $segmentHash,
            'prev_checkpoint_hash' => $prevCheckpointHash,
            'checkpoint_hash'      => $checkpointHash,
            'signature'            => $signature,
            'hash_version'         => AuditEventCanonicalizer::HASH_VERSION,
            'is_genesis'           => 0,
            'created_at'           => $createdAt,
        ])->execute();

        // ----------------------------------------------------------------
        // Step 8: build the read model, export, and return.
        // ----------------------------------------------------------------
        $checkpoint = new AuditCheckpoint([
            'uuid'                 => $uuid,
            'segment_start_id'     => $firstRowId,
            'segment_end_id'       => $lastRowId,
            'row_count'            => $count,
            'segment_hash'         => $segmentHash,
            'prev_checkpoint_hash' => $prevCheckpointHash,
            'checkpoint_hash'      => $checkpointHash,
            'signature'            => $signature,
            'hash_version'         => AuditEventCanonicalizer::HASH_VERSION,
            'is_genesis'           => false,
            'created_at'           => $createdAt,
        ]);

        try {
            $this->sink->export($checkpoint);
        } catch (\Throwable $e) {
            $this->logger->warning('audit.checkpoint_export_failed', [
                'error' => $e->getMessage(),
                'uuid'  => $uuid,
            ]);
        }

        return $checkpoint;
    }

    /** @param array<string, mixed> $latest */
    private function authenticatePristineGenesis(array $latest): string
    {
        if ($this->custody === null || !(bool) ($latest['is_genesis'] ?? false)) {
            return (string) ($latest['signature'] ?? '');
        }

        $checkpointId = (int) $latest['id'];
        $checkpointHash = (string) $latest['checkpoint_hash'];
        $signature = (string) ($latest['signature'] ?? '');
        $expected = $this->custody->sealCheckpoint($checkpointHash);

        if ($signature === '') {
            $updated = $this->database->update('audit_checkpoint')
                ->fields(['signature' => $expected])
                ->condition('id', $checkpointId)
                ->condition('checkpoint_hash', $checkpointHash)
                ->condition('signature', '')
                ->condition('is_genesis', 1)
                ->execute();

            if ($updated === 1) {
                return $expected;
            }

            $rows = iterator_to_array(
                $this->database->select('audit_checkpoint')
                    ->fields('audit_checkpoint', ['signature'])
                    ->condition('id', $checkpointId)
                    ->range(0, 1)
                    ->execute(),
                false,
            );
            $signature = $rows === [] ? '' : (string) $rows[0]['signature'];
        }

        if (!$this->custody->verifyCheckpoint($signature, $checkpointHash)) {
            throw new \RuntimeException(
                'Keyed audit checkpoint creation refused a genesis anchor authenticated by a different or malformed key.',
            );
        }

        return $signature;
    }

    /** @param array<string, mixed> $latest */
    private function assertActiveSigningAuthorized(array $latest): void
    {
        if ($this->custody === null || $this->custody->activeVersion() === null) {
            return;
        }
        $activeVersion = $this->custody->activeVersion();
        if ($this->custody->checkpointEnvelopeVersion((string) $latest['signature']) === $activeVersion) {
            return;
        }
        $checkpoints = iterator_to_array(
            $this->database->select('audit_checkpoint')->fields('audit_checkpoint', [
                'id', 'uuid', 'segment_start_id', 'segment_end_id', 'row_count', 'segment_hash',
                'prev_checkpoint_hash', 'checkpoint_hash', 'signature', 'hash_version',
                'is_genesis', 'pruned', 'prune_authorization', 'created_at',
            ])->orderBy('id', 'ASC')->execute(),
            false,
        );
        try {
            $window = new AuditCheckpointSuccessionVerifier(
                $this->database,
                $this->custody,
            )->window($checkpoints);
        } catch (\Throwable) {
            throw new \RuntimeException(
                'Keyed audit checkpoint creation refused a successor master version without a verified succession anchor.',
            );
        }
        if ($window->signingVersion !== $activeVersion
            || $window->pinnedThroughCheckpointId < (int) $latest['id']) {
            throw new \RuntimeException(
                'Keyed audit checkpoint creation refused a successor master version without a verified succession anchor.',
            );
        }
    }

    /** @return array{database: string, sink: string, logger: string, custody: string|null} */
    public function __debugInfo(): array
    {
        return [
            'database' => $this->database::class,
            'sink' => $this->sink::class,
            'logger' => $this->logger::class,
            'custody' => $this->custody === null ? null : '[NON_EXPORTING]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Audit checkpoint builders cannot be serialized.');
    }
}

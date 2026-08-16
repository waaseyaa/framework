<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

use Waaseyaa\Database\DatabaseInterface;

/** Verifies the append-only succession ledger and returns its authenticated coverage. @api */
final readonly class AuditCheckpointSuccessionVerifier
{
    private const string ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private DatabaseInterface $database,
        private AuditCheckpointCustody $custody,
    ) {}

    /** @param list<array<string, mixed>> $checkpoints */
    public function window(array $checkpoints): AuditSuccessionVerificationWindow
    {
        $records = iterator_to_array(
            $this->database->select('audit_checkpoint_succession')
                ->fields('audit_checkpoint_succession')
                ->orderBy('sequence', 'ASC')->execute(),
            false,
        );
        if ($records === []) {
            return new AuditSuccessionVerificationWindow(0, $this->custody->activeVersion() ?? 0, []);
        }

        $previousHash = self::ZERO_HASH;
        $expectedSequence = 1;
        foreach ($records as $record) {
            if ((int) $record['sequence'] !== $expectedSequence
                || !hash_equals($previousHash, (string) $record['previous_ledger_hash'])
                || !hash_equals(self::recordHash($record), (string) $record['record_hash'])) {
                throw new \RuntimeException('The audit succession ledger hash chain is not continuous.');
            }
            $previousHash = (string) $record['record_hash'];
            ++$expectedSequence;
        }
        $head = $records[array_key_last($records)];
        $body = self::bodyFromRecord($head);
        if (!$this->custody->verifySuccessionAnchor(
            (string) $head['signature'],
            self::json($body),
        )) {
            throw new \RuntimeException('The audit succession ledger head signature did not verify.');
        }
        $signingVersion = $this->custody->successionEnvelopeVersion((string) $head['signature']);
        if ($signingVersion === null) {
            throw new \RuntimeException('The audit succession ledger head signature is malformed.');
        }

        $terminalId = (int) $head['terminal_checkpoint_id'];
        $covered = array_values(array_filter(
            $checkpoints,
            static fn(array $checkpoint): bool => (int) $checkpoint['id'] <= $terminalId,
        ));
        if (count($covered) !== (int) $head['checkpoint_count']) {
            throw new \RuntimeException('The audit succession checkpoint count changed.');
        }
        $chainDigest = hash('sha256', 'waaseyaa.audit.checkpoint-succession-chain.v1');
        foreach ($covered as $checkpoint) {
            $chainDigest = hash('sha256', $chainDigest . "\x1f" . self::checkpointRecord($checkpoint));
        }
        if (!hash_equals((string) $head['chain_digest'], $chainDigest)) {
            throw new \RuntimeException('The audit succession checkpoint digest changed.');
        }
        $terminal = $covered === [] ? null : $covered[array_key_last($covered)];
        if (($terminal === null && ($terminalId !== 0
                || (string) $head['terminal_checkpoint_uuid'] !== ''
                || (int) $head['terminal_segment_end_id'] !== 0
                || (string) $head['terminal_checkpoint_hash'] !== self::ZERO_HASH))
            || ($terminal !== null && (
                (int) $terminal['id'] !== $terminalId
                || !hash_equals((string) $terminal['uuid'], (string) $head['terminal_checkpoint_uuid'])
                || (int) $terminal['segment_end_id'] !== (int) $head['terminal_segment_end_id']
                || !hash_equals((string) $terminal['checkpoint_hash'], (string) $head['terminal_checkpoint_hash'])
            ))) {
            throw new \RuntimeException('The audit succession terminal checkpoint identity changed.');
        }

        $prunedRows = iterator_to_array(
            $this->database->select('audit_checkpoint_succession_pruned')
                ->fields('audit_checkpoint_succession_pruned', [
                    'checkpoint_id', 'checkpoint_uuid', 'checkpoint_hash',
                ])->condition('anchor_sequence', (int) $head['sequence'])
                ->orderBy('checkpoint_id', 'ASC')->execute(),
            false,
        );
        $prunedDigest = hash('sha256', 'waaseyaa.audit.checkpoint-succession-pruned.v1');
        $evidence = [];
        foreach ($prunedRows as $row) {
            $canonical = [
                'checkpoint_id' => (int) $row['checkpoint_id'],
                'checkpoint_uuid' => (string) $row['checkpoint_uuid'],
                'checkpoint_hash' => (string) $row['checkpoint_hash'],
            ];
            $prunedDigest = hash('sha256', $prunedDigest . "\x1f" . self::json($canonical));
            $evidence[$canonical['checkpoint_id']] = [
                'uuid' => $canonical['checkpoint_uuid'],
                'hash' => $canonical['checkpoint_hash'],
            ];
        }
        if (count($prunedRows) !== (int) $head['pruned_checkpoint_count']
            || !hash_equals((string) $head['pruned_checkpoint_digest'], $prunedDigest)) {
            throw new \RuntimeException('The audit succession pruned-checkpoint evidence changed.');
        }
        foreach ($covered as $checkpoint) {
            $id = (int) $checkpoint['id'];
            if (isset($evidence[$id]) && (!(bool) $checkpoint['pruned']
                || !hash_equals($evidence[$id]['uuid'], (string) $checkpoint['uuid'])
                || !hash_equals($evidence[$id]['hash'], (string) $checkpoint['checkpoint_hash']))) {
                throw new \RuntimeException('Anchored audit prune evidence no longer matches its checkpoint.');
            }
        }

        return new AuditSuccessionVerificationWindow($terminalId, $signingVersion, $evidence);
    }

    /** @param array<string, mixed> $record */
    private static function recordHash(array $record): string
    {
        return hash('sha256', implode("\0", [
            'waaseyaa.audit.checkpoint-succession-ledger.v1',
            (string) $record['sequence'],
            (string) $record['record_kind'],
            self::json(self::bodyFromRecord($record)),
            (string) $record['signature'],
            (string) $record['previous_ledger_hash'],
            (string) $record['created_at'],
        ]));
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

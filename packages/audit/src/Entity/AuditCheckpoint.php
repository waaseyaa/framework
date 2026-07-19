<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Checkpoint read model for the audit tamper-evidence chain.
 *
 * `audit_checkpoint` is NOT a registered content entity type — it is a flat
 * OCAP chain-of-custody table, analogous to `audit_retention_policy`. Rows are
 * written by the checkpoint builder (WP2) through the raw DatabaseInterface and
 * read back as typed accessors via this class.
 *
 * Columns: id, uuid, segment_start_id, segment_end_id, row_count,
 * segment_hash, prev_checkpoint_hash, checkpoint_hash, signature,
 * hash_version, is_genesis, created_at.
 *
 * @api
 */
final class AuditCheckpoint extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'audit_checkpoint',
        array $entityKeys = ['id' => 'id', 'uuid' => 'uuid'],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }

    public function getUuid(): string
    {
        return (string) ($this->get('uuid') ?? '');
    }

    /** First audit_event.id covered by this checkpoint's segment. */
    public function getSegmentStartId(): int
    {
        return (int) ($this->get('segment_start_id') ?? 0);
    }

    /** Last audit_event.id covered by this checkpoint's segment. */
    public function getSegmentEndId(): int
    {
        return (int) ($this->get('segment_end_id') ?? 0);
    }

    /** Number of audit_event rows in the segment. */
    public function getRowCount(): int
    {
        return (int) ($this->get('row_count') ?? 0);
    }

    /**
     * Hash over the rows in the segment (computed by the builder in WP2).
     * The genesis anchor stores {@see \Waaseyaa\Audit\Integrity\AuditEventCanonicalizer::GENESIS_HASH}
     * because it predates chaining.
     */
    public function getSegmentHash(): string
    {
        return (string) ($this->get('segment_hash') ?? '');
    }

    /**
     * Hash of the immediately preceding checkpoint.
     * The genesis anchor stores {@see \Waaseyaa\Audit\Integrity\AuditEventCanonicalizer::GENESIS_HASH}.
     */
    public function getPrevCheckpointHash(): string
    {
        return (string) ($this->get('prev_checkpoint_hash') ?? '');
    }

    /**
     * Canonical checkpoint hash — computed by
     * {@see \Waaseyaa\Audit\Integrity\AuditCheckpointHasher::checkpointHash()}.
     */
    public function getCheckpointHash(): string
    {
        return (string) ($this->get('checkpoint_hash') ?? '');
    }

    /**
     * Optional detached signature over the checkpoint_hash (empty until WP5
     * wires a signing key).
     */
    public function getSignature(): string
    {
        return (string) ($this->get('signature') ?? '');
    }

    /** Hash version tag — mirrors {@see \Waaseyaa\Audit\Integrity\AuditEventCanonicalizer::HASH_VERSION}. */
    public function getHashVersion(): string
    {
        return (string) ($this->get('hash_version') ?? 'v1');
    }

    /**
     * True for the genesis anchor row that is inserted once by ensureSchema()
     * when no prior checkpoints exist. The genesis row "predates chaining" —
     * its segment_hash and prev_checkpoint_hash are both GENESIS_HASH.
     */
    public function isGenesis(): bool
    {
        return (bool) ($this->get('is_genesis') ?? false);
    }

    /**
     * True when the segment covered by this checkpoint has been sanctioned-pruned
     * (WP4: audit:prune at checkpoint boundaries). A pruned checkpoint retains
     * its chain link so the verifier can continue walking the chain; only the
     * audit_event rows in the segment are deleted.
     */
    public function isPruned(): bool
    {
        return (bool) ($this->get('pruned') ?? false);
    }

    public function getCreatedAt(): string
    {
        return (string) ($this->get('created_at') ?? '');
    }
}

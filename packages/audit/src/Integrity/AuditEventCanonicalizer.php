<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

/**
 * Deterministic, driver-independent canonical string and row hash over an
 * audit_event row's CONTENT columns.
 *
 * The canonical string is length-prefixed and order-fixed so that no field
 * value can forge a delimiter and two distinct rows can never collide on the
 * same hash input.
 *
 * Usage by the checkpoint builder (WP2) and verifier (WP3): neither of those
 * WPs needs to re-derive the encoding — they call {@see canonicalize()} and
 * {@see rowHash()} directly.
 *
 * `row_hash` and `prev_hash` are NOT included in the canonical string — they
 * are chain metadata, not content, so including them would create a circular
 * dependency during the sealing step.
 *
 * @api
 */
final class AuditEventCanonicalizer
{
    /** Bump when the canonical encoding changes so old hashes stay interpretable. */
    public const string HASH_VERSION = 'v1';

    /** Sentinel prev_hash for the first link in a chain / the genesis anchor. */
    public const string GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Content columns hashed, in FIXED order. row_hash/prev_hash are excluded.
     *
     * @var list<string>
     */
    private const array CONTENT_COLUMNS = [
        'id',
        'uuid',
        'event_kind',
        'account_uid',
        'actor_uid',
        'entity_type_id',
        'entity_uuid',
        'subject_uri',
        'outcome',
        'severity',
        'attributes',
        'created_at',
    ];

    /**
     * Length-prefixed, order-fixed, NULL-explicit encoding so no field value
     * can forge a delimiter and two distinct rows can never collide.
     *
     * Format: `v1 \x1f col:len:value \x1f col:len:value …`
     * NULL values are represented as the literal string `\x00NULL` (6 bytes)
     * and their length prefix reflects that, so NULL is distinct from both
     * the empty string (length 0) and the string "NULL" (length 4).
     *
     * @param array<string, mixed> $row
     */
    public static function canonicalize(array $row): string
    {
        $parts = [self::HASH_VERSION];
        foreach (self::CONTENT_COLUMNS as $col) {
            $v = $row[$col] ?? null;
            $s = $v === null ? "\x00NULL" : (string) $v;
            $parts[] = $col . ':' . strlen($s) . ':' . $s;
        }

        return implode("\x1f", $parts);
    }

    /**
     * Computes the row hash: sha256( canonical(row) || "\x1f" || prevHash ). Hex.
     *
     * Chaining prevHash into the hash input means any tampering of an earlier
     * row breaks all subsequent hashes — the chain is no longer self-consistent.
     *
     * @param array<string, mixed> $row
     */
    public static function rowHash(array $row, string $prevHash): string
    {
        return hash('sha256', self::canonicalize($row) . "\x1f" . $prevHash);
    }
}

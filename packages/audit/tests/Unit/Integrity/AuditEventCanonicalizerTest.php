<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Integrity\AuditEventCanonicalizer;

/**
 * Unit coverage for {@see AuditEventCanonicalizer}: determinism, sensitivity
 * to every content field, null handling, and length-prefix collision prevention.
 */
#[CoversClass(AuditEventCanonicalizer::class)]
final class AuditEventCanonicalizerTest extends TestCase
{
    /**
     * Returns a minimal, fully populated audit_event row (all content columns).
     *
     * @return array<string, mixed>
     */
    private function baseRow(): array
    {
        return [
            'id'             => 42,
            'uuid'           => 'b1e2c3d4-0000-0000-0000-000000000001',
            'event_kind'     => 'entity.write',
            'account_uid'    => 7,
            'actor_uid'      => 7,
            'entity_type_id' => 'node',
            'entity_uuid'    => 'eeeeeeee-0000-0000-0000-000000000001',
            'subject_uri'    => '/entities/node/eeeeeeee',
            'outcome'        => 'allowed',
            'severity'       => 'info',
            'attributes'     => '{}',
            'created_at'     => '2026-01-01 00:00:00',
        ];
    }

    // ------------------------------------------------------------------
    // Determinism
    // ------------------------------------------------------------------

    #[Test]
    public function same_row_produces_the_same_canonical_string(): void
    {
        $row = $this->baseRow();

        $this->assertSame(
            AuditEventCanonicalizer::canonicalize($row),
            AuditEventCanonicalizer::canonicalize($row),
        );
    }

    #[Test]
    public function same_row_produces_the_same_row_hash(): void
    {
        $row = $this->baseRow();
        $prev = AuditEventCanonicalizer::GENESIS_HASH;

        $this->assertSame(
            AuditEventCanonicalizer::rowHash($row, $prev),
            AuditEventCanonicalizer::rowHash($row, $prev),
        );
    }

    // ------------------------------------------------------------------
    // Field sensitivity: changing any single content field changes the hash
    // ------------------------------------------------------------------

    #[Test]
    public function changing_id_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['id'] = 999;
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_uuid_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['uuid'] = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_event_kind_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['event_kind'] = 'entity.delete';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_account_uid_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['account_uid'] = 99;
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_actor_uid_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['actor_uid'] = 88;
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_entity_type_id_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['entity_type_id'] = 'taxonomy_term';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_entity_uuid_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['entity_uuid'] = 'deadbeef-dead-dead-dead-deaddeadbeef';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_subject_uri_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['subject_uri'] = '/entities/node/different';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_outcome_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['outcome'] = 'denied';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_severity_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['severity'] = 'warning';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_attributes_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['attributes'] = '{"tampered":true}';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    #[Test]
    public function changing_created_at_changes_the_hash(): void
    {
        $row = $this->baseRow();
        $original = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $row['created_at'] = '2026-12-31 23:59:59';
        $this->assertNotSame($original, AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH));
    }

    // ------------------------------------------------------------------
    // NULL handling: null actor_uid is distinct from '' and from 0
    // ------------------------------------------------------------------

    #[Test]
    public function null_actor_uid_is_distinct_from_empty_string(): void
    {
        $rowNull = $this->baseRow();
        $rowNull['actor_uid'] = null;

        $rowEmpty = $this->baseRow();
        $rowEmpty['actor_uid'] = '';

        $this->assertNotSame(
            AuditEventCanonicalizer::canonicalize($rowNull),
            AuditEventCanonicalizer::canonicalize($rowEmpty),
        );
    }

    #[Test]
    public function null_actor_uid_is_distinct_from_zero(): void
    {
        $rowNull = $this->baseRow();
        $rowNull['actor_uid'] = null;

        $rowZero = $this->baseRow();
        $rowZero['actor_uid'] = 0;

        $this->assertNotSame(
            AuditEventCanonicalizer::canonicalize($rowNull),
            AuditEventCanonicalizer::canonicalize($rowZero),
        );
    }

    // ------------------------------------------------------------------
    // rowHash chain sensitivity: different prevHash → different row hash
    // ------------------------------------------------------------------

    #[Test]
    public function row_hash_differs_when_prev_hash_differs(): void
    {
        $row = $this->baseRow();
        $hash1 = AuditEventCanonicalizer::rowHash($row, AuditEventCanonicalizer::GENESIS_HASH);
        $hash2 = AuditEventCanonicalizer::rowHash($row, str_repeat('a', 64));

        $this->assertNotSame($hash1, $hash2);
    }

    // ------------------------------------------------------------------
    // Length-prefix collision prevention
    //
    // A naive concatenation encoder (col1 . col2 . …) lets two distinct rows
    // produce the same input string. Length-prefixing prevents this.
    //
    // Example: for columns subject_uri and outcome (adjacent in the fixed
    // order), the pair ('ab', '') and ('a', 'b') would concatenate to the
    // same string "ab", but with length prefixes they become
    // "subject_uri:2:ab … outcome:0:" vs "subject_uri:1:a … outcome:1:b".
    // ------------------------------------------------------------------

    #[Test]
    public function length_prefix_prevents_adjacent_field_boundary_collision(): void
    {
        // Row A: subject_uri = 'ab', outcome = '' (would be invalid in practice,
        // but the canonicalizer must handle any string to prevent spoofing).
        $rowA = $this->baseRow();
        $rowA['subject_uri'] = 'ab';
        $rowA['outcome'] = '';

        // Row B: subject_uri = 'a', outcome = 'b'
        $rowB = $this->baseRow();
        $rowB['subject_uri'] = 'a';
        $rowB['outcome'] = 'b';

        // Both rows have different content — canonicalize must distinguish them.
        $this->assertNotSame(
            AuditEventCanonicalizer::canonicalize($rowA),
            AuditEventCanonicalizer::canonicalize($rowB),
        );

        // Their row hashes must therefore also differ.
        $prev = AuditEventCanonicalizer::GENESIS_HASH;
        $this->assertNotSame(
            AuditEventCanonicalizer::rowHash($rowA, $prev),
            AuditEventCanonicalizer::rowHash($rowB, $prev),
        );
    }
}

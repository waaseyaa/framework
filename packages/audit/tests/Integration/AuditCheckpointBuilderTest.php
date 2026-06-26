<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditCheckpoint;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\AuditCheckpointHasher;
use Waaseyaa\Audit\Integrity\AuditEventCanonicalizer;
use Waaseyaa\Audit\Integrity\CheckpointSink;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Database\DBALDatabase;

/**
 * Integration proof for WP2: checkpoint builder seals segments correctly.
 *
 * All assertions run against an in-memory SQLite instance via DBALDatabase::createSqlite()
 * to keep the test hermetic and fast.
 */
#[CoversClass(AuditCheckpointBuilder::class)]
final class AuditCheckpointBuilderTest extends TestCase
{
    private DBALDatabase $db;

    /** @var list<AuditCheckpoint> */
    private array $exported = [];

    private CheckpointSink $spy;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($this->db)->ensureSchema();

        $exported = &$this->exported;
        $this->spy = new class ($exported) implements CheckpointSink {
            /** @param list<AuditCheckpoint> $exported */
            public function __construct(private array &$exported) {}

            public function export(AuditCheckpoint $checkpoint): void
            {
                $this->exported[] = $checkpoint;
            }
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function insertEvent(string $uuid, string $kind = 'entity.write', int $accountUid = 1): void
    {
        $this->db->insert('audit_event')->values([
            'uuid'           => $uuid,
            'event_kind'     => $kind,
            'account_uid'    => $accountUid,
            'actor_uid'      => $accountUid,
            'entity_type_id' => 'node',
            'entity_uuid'    => 'eeeeeeee-0000-0000-0000-000000000001',
            'subject_uri'    => '/entities/node/test',
            'outcome'        => 'allowed',
            'severity'       => 'info',
            'attributes'     => '{}',
            'created_at'     => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        ])->execute();
    }

    private function makeBuilder(): AuditCheckpointBuilder
    {
        return new AuditCheckpointBuilder($this->db, $this->spy);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function testBuildWithNoEventsReturnsNull(): void
    {
        $builder    = $this->makeBuilder();
        $checkpoint = $builder->build();

        self::assertNull($checkpoint, 'build() on empty (genesis only) db must return null');
        self::assertCount(0, $this->exported, 'no export when null returned');
    }

    #[Test]
    public function testBuildSealsSingleBatch(): void
    {
        $this->insertEvent('u-a');
        $this->insertEvent('u-b');
        $this->insertEvent('u-c');

        $checkpoint = $this->makeBuilder()->build();

        self::assertNotNull($checkpoint);
        self::assertSame(1, $checkpoint->getSegmentStartId(), 'segment starts at first event');
        self::assertSame(3, $checkpoint->getSegmentEndId(), 'segment ends at last event');
        self::assertSame(3, $checkpoint->getRowCount(), 'three rows sealed');
        self::assertNotEmpty($checkpoint->getUuid());
        self::assertNotEmpty($checkpoint->getCheckpointHash());

        // Verify row_hash and prev_hash were written on each event row.
        $rows = iterator_to_array(
            $this->db->select('audit_event')
                ->fields('audit_event', ['id', 'row_hash', 'prev_hash'])
                ->orderBy('id', 'ASC')
                ->execute(),
            false,
        );

        self::assertCount(3, $rows);
        self::assertNotSame('', $rows[0]['row_hash'], 'first row must have row_hash set');
        self::assertSame(AuditEventCanonicalizer::GENESIS_HASH, $rows[0]['prev_hash'], 'first row prev_hash must be genesis');
        self::assertSame($rows[0]['row_hash'], $rows[1]['prev_hash'], 'second row prev_hash must equal first row_hash');
        self::assertSame($rows[1]['row_hash'], $rows[2]['prev_hash'], 'third row prev_hash must equal second row_hash');

        // The spy received the checkpoint.
        self::assertCount(1, $this->exported);
        self::assertSame($checkpoint->getUuid(), $this->exported[0]->getUuid());
    }

    #[Test]
    public function testBuildWithNoNewEventsIsIdempotent(): void
    {
        $this->insertEvent('u-x');
        $builder = $this->makeBuilder();

        $first  = $builder->build();
        $second = $builder->build();

        self::assertNotNull($first, 'first build must return a checkpoint');
        self::assertNull($second, 'second build with no new events must return null');
        self::assertCount(1, $this->exported, 'only one export');
    }

    #[Test]
    public function testBuildChainsCorrectly(): void
    {
        $this->insertEvent('u-1');
        $this->insertEvent('u-2');

        $builder = $this->makeBuilder();
        $first   = $builder->build();
        self::assertNotNull($first);

        // Insert more events after the first checkpoint.
        $this->insertEvent('u-3');
        $this->insertEvent('u-4');

        $second = $builder->build();
        self::assertNotNull($second);

        // Second checkpoint must chain off the first.
        self::assertSame(
            $first->getCheckpointHash(),
            $second->getPrevCheckpointHash(),
            'second checkpoint prev_checkpoint_hash must equal first checkpoint_hash',
        );

        // Segment IDs must not overlap.
        self::assertGreaterThan($first->getSegmentEndId(), $second->getSegmentStartId());
        self::assertSame(2, $second->getRowCount(), 'second segment has 2 rows');

        // Verify the checkpoint hash is correctly computed.
        $expectedHash = AuditCheckpointHasher::checkpointHash(
            $second->getSegmentStartId(),
            $second->getSegmentEndId(),
            $second->getRowCount(),
            $second->getSegmentHash(),
            $second->getPrevCheckpointHash(),
        );
        self::assertSame($expectedHash, $second->getCheckpointHash());
    }
}

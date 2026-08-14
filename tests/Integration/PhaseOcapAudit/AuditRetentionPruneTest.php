<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseOcapAudit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\CLI\Command\Audit\PruneCommand;
use Waaseyaa\CLI\Testing\CapturingSymfonyCommandIO;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * Integration test for the `audit:prune` CLI command (T-O §2).
 *
 * Verifies:
 *   - Seeded events split by age: 6 older than 1h, 4 newer.
 *   - After `audit:prune --older-than=PT1H`: 6 rows deleted, 4 remain.
 *   - Exactly one `audit.retention_pruned` self-audit event is emitted
 *     with `attributes.deleted_count = 6`.
 *   - Remaining count: 4 seeded + 1 self-audit = 5 total.
 */
#[CoversNothing]
final class AuditRetentionPruneTest extends TestCase
{
    private DBALDatabase $database;
    private AuditWriterInterface $writer;
    private AuditQueryInterface $query;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();

        RuntimeSchemaMigrations::audit($this->database);

        // Production wiring: the writer appends through the append-only decorator;
        // the prune command and reads use the raw database directly. audit_event is
        // a plain OCAP log table, not a registered entity.
        $this->writer = new AuditEventWriter(new AppendOnlyAuditDatabase($this->database));
        $this->query = new AuditEventQuery($this->database);
    }

    #[Test]
    public function pruneDeletesOlderEventsAndEmitsSelfAudit(): void
    {
        $now = new \DateTimeImmutable('now');
        $oldTime = $now->sub(new \DateInterval('PT2H')); // 2 hours ago — older than PT1H cutoff
        $newTime = $now->sub(new \DateInterval('PT30M')); // 30 min ago — newer than PT1H cutoff

        // Insert 6 old events and 4 new events directly via DB for exact timing control.
        $conn = $this->database->getConnection();

        for ($i = 0; $i < 6; $i++) {
            $conn->executeStatement(
                "INSERT INTO audit_event (uuid, event_kind, account_uid, subject_uri, outcome, severity, attributes, created_at)
                 VALUES (?, 'entity.read', 1, '/old/" . $i . "', 'allowed', 'info', '{}', ?)",
                [
                    sprintf('old-event-%d-%s', $i, uniqid()),
                    $oldTime->format('Y-m-d H:i:s'),
                ],
            );
        }

        for ($i = 0; $i < 4; $i++) {
            $conn->executeStatement(
                "INSERT INTO audit_event (uuid, event_kind, account_uid, subject_uri, outcome, severity, attributes, created_at)
                 VALUES (?, 'entity.write', 1, '/new/" . $i . "', 'allowed', 'info', '{}', ?)",
                [
                    sprintf('new-event-%d-%s', $i, uniqid()),
                    $newTime->format('Y-m-d H:i:s'),
                ],
            );
        }

        // Verify seed.
        $totalBefore = $this->query->count(new AuditQuery());
        self::assertSame(10, $totalBefore, 'Seed must have 10 events');

        // Build and run the prune command.
        $command = new PruneCommand($this->query, $this->writer, $this->database);
        $io = $this->makeIo(['older-than' => 'PT1H', 'confirm' => true]);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode, 'Command must exit 0');

        // After prune: 4 new events + 1 self-audit = 5 remaining.
        $totalAfter = $this->query->count(new AuditQuery());
        self::assertSame(5, $totalAfter, '4 new events + 1 self-audit must remain (5)');

        // Verify 6 events were deleted (10 - 5 = 5, but self-audit adds 1, so 10 - 6 + 1 = 5 ✓).
        $outputLine = $io->outputLines()[0] ?? '';
        self::assertStringContainsString('6', $outputLine, 'Output must mention 6 pruned events');

        // Verify exactly one `audit.retention_pruned` self-audit event exists.
        $pruneEvents = iterator_to_array($this->query->findBy(new AuditQuery(
            kinds: [AuditEventKind::AuditRetentionPruned],
        )));
        self::assertCount(1, $pruneEvents, 'Exactly one self-audit event must be emitted');

        $pruneEvent = $pruneEvents[0];
        $attrs = $pruneEvent->getAttributes();
        self::assertSame(6, $attrs['deleted_count'], 'Self-audit must record deleted_count = 6');
    }

    #[Test]
    public function dryRunDoesNotDeleteOrEmitSelfAudit(): void
    {
        $conn = $this->database->getConnection();
        $oldTime = new \DateTimeImmutable('now')->sub(new \DateInterval('PT2H'));

        for ($i = 0; $i < 3; $i++) {
            $conn->executeStatement(
                "INSERT INTO audit_event (uuid, event_kind, account_uid, subject_uri, outcome, severity, attributes, created_at)
                 VALUES (?, 'entity.read', 1, '/dry/" . $i . "', 'allowed', 'info', '{}', ?)",
                [sprintf('dry-event-%d-%s', $i, uniqid()), $oldTime->format('Y-m-d H:i:s')],
            );
        }

        $command = new PruneCommand($this->query, $this->writer, $this->database);
        $io = $this->makeIo(['older-than' => 'PT1H', 'dry-run' => true]);

        $exitCode = $command->execute($io);

        self::assertSame(0, $exitCode);
        self::assertSame(3, $this->query->count(new AuditQuery()), 'dry-run must not delete rows');
        self::assertCount(
            0,
            iterator_to_array($this->query->findBy(new AuditQuery(
                kinds: [AuditEventKind::AuditRetentionPruned],
            ))),
            'dry-run must not emit self-audit event',
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function makeIo(array $options): CapturingSymfonyCommandIO
    {
        return new CapturingSymfonyCommandIO($options);
    }

}

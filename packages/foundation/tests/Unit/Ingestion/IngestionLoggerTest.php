<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\Envelope;
use Waaseyaa\Foundation\Ingestion\IngestionError;
use Waaseyaa\Foundation\Ingestion\IngestionErrorCode;
use Waaseyaa\Foundation\Ingestion\IngestionLogEntry;
use Waaseyaa\Foundation\Ingestion\IngestionLogger;

#[CoversClass(IngestionLogger::class)]
final class IngestionLoggerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_ingestion_log_' . uniqid();
        mkdir($this->projectRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    // ------------------------------------------------------------------
    // Append + read
    // ------------------------------------------------------------------

    #[Test]
    public function logAppendsAndReadReturnsEntries(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        $logger->log(IngestionLogEntry::success($this->makeEnvelope()));

        $entries = $logger->read();
        $this->assertCount(1, $entries);
        $this->assertSame('accepted', $entries[0]['status']);
        $this->assertSame('core.note', $entries[0]['type']);
    }

    #[Test]
    public function multipleEntriesAppendedCorrectly(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        $logger->log(IngestionLogEntry::success($this->makeEnvelope()));
        $logger->log(IngestionLogEntry::payloadFailure($this->makeEnvelope(), [
            new IngestionError(
                code:    IngestionErrorCode::PAYLOAD_FIELD_MISSING,
                message: 'Missing title',
                field:   'title',
            ),
        ]));

        $entries = $logger->read();
        $this->assertCount(2, $entries);
        $this->assertSame('accepted', $entries[0]['status']);
        $this->assertSame('rejected', $entries[1]['status']);
    }

    // ------------------------------------------------------------------
    // Status filtering
    // ------------------------------------------------------------------

    #[Test]
    public function readFiltersbyStatus(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        $logger->log(IngestionLogEntry::success($this->makeEnvelope()));
        $logger->log(IngestionLogEntry::payloadFailure($this->makeEnvelope(), [
            new IngestionError(
                code:    IngestionErrorCode::PAYLOAD_FIELD_MISSING,
                message: 'Missing title',
                field:   'title',
            ),
        ]));

        $accepted = $logger->read('accepted');
        $rejected = $logger->read('rejected');

        $this->assertCount(1, $accepted);
        $this->assertCount(1, $rejected);
    }

    // ------------------------------------------------------------------
    // Empty log
    // ------------------------------------------------------------------

    #[Test]
    public function readReturnsEmptyWhenNoFile(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        $this->assertSame([], $logger->read());
    }

    // ------------------------------------------------------------------
    // Log structure
    // ------------------------------------------------------------------

    #[Test]
    public function loggedEntryContainsAllFields(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        $logger->log(IngestionLogEntry::success($this->makeEnvelope()));

        $entries = $logger->read();
        $entry = $entries[0];

        $this->assertArrayHasKey('source', $entry);
        $this->assertArrayHasKey('type', $entry);
        $this->assertArrayHasKey('status', $entry);
        $this->assertArrayHasKey('trace_id', $entry);
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('logged_at', $entry);
        $this->assertArrayHasKey('tenant_id', $entry);
    }

    #[Test]
    public function failureEntryIncludesErrors(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        $errors = [
            new IngestionError(
                code:    IngestionErrorCode::PAYLOAD_FIELD_MISSING,
                message: "Required field 'title' is missing.",
                field:   'title',
                traceId: '550e8400-e29b-41d4-a716-446655440000',
            ),
            new IngestionError(
                code:    IngestionErrorCode::PAYLOAD_FIELD_READ_ONLY,
                message: "Field 'id' is read-only.",
                field:   'id',
                traceId: '550e8400-e29b-41d4-a716-446655440000',
            ),
        ];

        $logger->log(IngestionLogEntry::payloadFailure($this->makeEnvelope(), $errors));

        $entries = $logger->read();
        $this->assertCount(2, $entries[0]['errors']);
        $this->assertSame('PAYLOAD_FIELD_MISSING', $entries[0]['errors'][0]['code']);
        $this->assertSame('PAYLOAD_FIELD_READ_ONLY', $entries[0]['errors'][1]['code']);
    }

    // ------------------------------------------------------------------
    // Prune
    // ------------------------------------------------------------------

    #[Test]
    public function pruneRemovesOldEntries(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        // Write an entry with old logged_at.
        $old = new IngestionLogEntry(
            source:    'manual',
            type:      'core.note',
            status:    'accepted',
            traceId:   'trace-old',
            timestamp: '2025-01-01T00:00:00+00:00',
            loggedAt:  '2025-01-01T00:00:00+00:00',
        );
        $logger->log($old);

        // Write a recent entry.
        $logger->log(IngestionLogEntry::success($this->makeEnvelope()));

        $logger->prune(30);

        $entries = $logger->read();
        $this->assertCount(1, $entries);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $entries[0]['trace_id']);
    }

    #[Test]
    public function pruneOnMissingFileDoesNothing(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        // Should not throw.
        $logger->prune();

        $this->assertSame([], $logger->read());
    }

    // ------------------------------------------------------------------
    // Malformed lines
    // ------------------------------------------------------------------

    #[Test]
    public function malformedLinesAreSkipped(): void
    {
        $dir = $this->projectRoot . '/storage/framework';
        mkdir($dir, 0755, true);

        $file = $dir . '/ingestion.jsonl';
        file_put_contents($file, "not-json\n" . json_encode(['status' => 'accepted', 'source' => 'manual']) . "\n");

        $logger = new IngestionLogger($this->projectRoot);
        $entries = $logger->read();

        $this->assertCount(1, $entries);
        $this->assertSame('accepted', $entries[0]['status']);
    }

    // ------------------------------------------------------------------
    // Prune edge cases
    // ------------------------------------------------------------------

    #[Test]
    public function pruneKeepsEntriesWithUnparseableLoggedAt(): void
    {
        $dir = $this->projectRoot . '/storage/framework';
        mkdir($dir, 0755, true);

        $file = $dir . '/ingestion.jsonl';

        $unparseable = json_encode([
            'source'    => 'manual',
            'type'      => 'core.note',
            'status'    => 'accepted',
            'trace_id'  => 'trace-bad-date',
            'timestamp' => '2026-03-08T17:00:00+00:00',
            'logged_at' => 'NOT-A-DATE',
        ], JSON_THROW_ON_ERROR);

        $recent = json_encode([
            'source'    => 'manual',
            'type'      => 'core.note',
            'status'    => 'accepted',
            'trace_id'  => 'trace-recent',
            'timestamp' => '2026-03-08T17:00:00+00:00',
            'logged_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        file_put_contents($file, $unparseable . "\n" . $recent . "\n");

        $logger = new IngestionLogger($this->projectRoot);
        $logger->prune(30);

        $entries = $logger->read();
        $this->assertCount(2, $entries);

        $traceIds = array_column($entries, 'trace_id');
        $this->assertContains('trace-bad-date', $traceIds);
        $this->assertContains('trace-recent', $traceIds);
    }

    #[Test]
    public function pruneRemovesAllWhenAllExpired(): void
    {
        $logger = new IngestionLogger($this->projectRoot);

        $old = new IngestionLogEntry(
            source:    'manual',
            type:      'core.note',
            status:    'accepted',
            traceId:   'trace-old-1',
            timestamp: '2025-01-01T00:00:00+00:00',
            loggedAt:  '2025-01-01T00:00:00+00:00',
        );
        $logger->log($old);

        $logger->prune(30);

        $entries = $logger->read();
        $this->assertSame([], $entries);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeEnvelope(): Envelope
    {
        return new Envelope(
            source:    'manual',
            type:      'core.note',
            payload:   ['title' => 'Hello'],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId:   '550e8400-e29b-41d4-a716-446655440000',
            tenantId:  'tenant-1',
        );
    }
}

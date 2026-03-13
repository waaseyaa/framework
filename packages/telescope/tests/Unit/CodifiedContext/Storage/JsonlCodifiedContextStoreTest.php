<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\CodifiedContextEntry;
use Waaseyaa\Telescope\CodifiedContext\Storage\CodifiedContextStoreInterface;
use Waaseyaa\Telescope\CodifiedContext\Storage\JsonlCodifiedContextStore;
use Waaseyaa\Telescope\TelescopeEntry;

#[CoversClass(JsonlCodifiedContextStore::class)]
final class JsonlCodifiedContextStoreTest extends TestCase
{
    private string $tempDir;
    private JsonlCodifiedContextStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_jsonl_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->store = new JsonlCodifiedContextStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $file = $this->tempDir . '/telescope_cc.jsonl';
        if (file_exists($file)) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function implementsCodifiedContextStoreInterface(): void
    {
        $this->assertInstanceOf(CodifiedContextStoreInterface::class, $this->store);
    }

    #[Test]
    public function storeWritesLineToFile(): void
    {
        $this->store->store('drift_detected', ['session_id' => 'sess-1', 'severity' => 'high']);

        $file = $this->tempDir . '/telescope_cc.jsonl';
        $this->assertFileExists($file);
        $lines = explode("\n", trim(file_get_contents($file)));
        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertSame('drift_detected', $decoded['type']);
        $this->assertSame('sess-1', $decoded['session_id']);
    }

    #[Test]
    public function queryReturnsTelescopeEntriesFilteredByType(): void
    {
        $this->store->store('drift_detected', ['session_id' => 'sess-1']);
        $this->store->store('validation_passed', ['session_id' => 'sess-1']);
        $this->store->store('drift_detected', ['session_id' => 'sess-2']);

        $entries = $this->store->query('drift_detected');

        $this->assertCount(2, $entries);
        $this->assertContainsOnlyInstancesOf(TelescopeEntry::class, $entries);
        foreach ($entries as $entry) {
            $this->assertSame('drift_detected', $entry->type);
        }
    }

    #[Test]
    public function queryReturnsEntriesInDescendingOrder(): void
    {
        $this->store->store('event', ['session_id' => 'sess-1', 'order' => 'first']);
        usleep(1000);
        $this->store->store('event', ['session_id' => 'sess-1', 'order' => 'second']);

        $entries = $this->store->query('event');

        $this->assertSame('second', $entries[0]->data['order']);
        $this->assertSame('first', $entries[1]->data['order']);
    }

    #[Test]
    public function queryBySessionReturnsCodifiedContextEntries(): void
    {
        $this->store->store('drift_detected', ['session_id' => 'sess-A', 'severity' => 'low']);
        $this->store->store('drift_detected', ['session_id' => 'sess-B', 'severity' => 'high']);
        $this->store->store('validation', ['session_id' => 'sess-A']);

        $entries = $this->store->queryBySession('sess-A');

        $this->assertCount(2, $entries);
        $this->assertContainsOnlyInstancesOf(CodifiedContextEntry::class, $entries);
        foreach ($entries as $entry) {
            $this->assertSame('sess-A', $entry->sessionId);
        }
    }

    #[Test]
    public function queryBySessionReturnsEmptyForUnknownSession(): void
    {
        $this->store->store('event', ['session_id' => 'sess-X']);

        $entries = $this->store->queryBySession('sess-UNKNOWN');

        $this->assertSame([], $entries);
    }

    #[Test]
    public function queryByDriftSeverityFiltersCorrectly(): void
    {
        $this->store->store('drift', ['session_id' => 'sess-1', 'severity' => 'high']);
        $this->store->store('drift', ['session_id' => 'sess-2', 'severity' => 'low']);
        $this->store->store('drift', ['session_id' => 'sess-3', 'severity' => 'high']);

        $highEntries = $this->store->queryByDriftSeverity('high');
        $lowEntries = $this->store->queryByDriftSeverity('low');

        $this->assertCount(2, $highEntries);
        $this->assertCount(1, $lowEntries);
        $this->assertContainsOnlyInstancesOf(CodifiedContextEntry::class, $highEntries);
    }

    #[Test]
    public function pruneRemovesOldEntries(): void
    {
        $this->store->store('event', ['session_id' => 'sess-1']);
        $this->store->store('event', ['session_id' => 'sess-2']);

        $pruned = $this->store->prune(new \DateTimeImmutable('+1 second'));

        $this->assertSame(2, $pruned);
        $this->assertSame([], $this->store->query('event'));
    }

    #[Test]
    public function pruneKeepsRecentEntries(): void
    {
        $this->store->store('event', ['session_id' => 'sess-1']);

        $pruned = $this->store->prune(new \DateTimeImmutable('-1 day'));

        $this->assertSame(0, $pruned);
        $this->assertCount(1, $this->store->query('event'));
    }

    #[Test]
    public function clearTruncatesFile(): void
    {
        $this->store->store('event', ['session_id' => 'sess-1']);
        $this->store->store('event', ['session_id' => 'sess-2']);

        $this->store->clear();

        $this->assertSame([], $this->store->query('event'));

        $file = $this->tempDir . '/telescope_cc.jsonl';
        $this->assertSame('', file_get_contents($file));
    }

    #[Test]
    public function queryOnMissingFileReturnsEmpty(): void
    {
        $entries = $this->store->query('event');

        $this->assertSame([], $entries);
    }

    #[Test]
    public function queryByEventTypeFiltersOnDataField(): void
    {
        $this->store->store('cc_event', ['session_id' => 'sess-1', 'event_type' => 'drift_detected']);
        $this->store->store('cc_event', ['session_id' => 'sess-2', 'event_type' => 'validation_passed']);

        $entries = $this->store->queryByEventType('drift_detected');

        $this->assertCount(1, $entries);
        $this->assertSame('drift_detected', $entries[0]->data['event_type']);
    }
}

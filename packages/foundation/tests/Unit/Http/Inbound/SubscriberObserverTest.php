<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Inbound;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Http\Inbound\SubscriberObserver;

/**
 * Unit tests for the M5D SubscriberObserver adapter (T004).
 *
 * FR-004: active rows returned; stale rows dropped; malformed file → empty;
 * concurrent-write fixture exercises atomic rename path.
 */
#[CoversClass(SubscriberObserver::class)]
final class SubscriberObserverTest extends TestCase
{
    private string $tmpDir;
    private string $jsonPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_subscriber_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->jsonPath = $this->tmpDir . '/subscribers.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->jsonPath)) {
            unlink($this->jsonPath);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->jsonPath, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function activeRow(int $accountId = 1, ?string $label = 'Alice', array $channels = ['admin']): array
    {
        return [
            'accountId' => $accountId,
            'accountLabel' => $label,
            'channels' => $channels,
            'connectedSince' => microtime(true) - 5.0,
            'lastHeartbeat' => microtime(true), // active — heartbeat just now
            'connectionId' => 'abc123def456abcd',
        ];
    }

    private function staleRow(int $accountId = 2): array
    {
        return [
            'accountId' => $accountId,
            'accountLabel' => null,
            'channels' => ['realtime'],
            'connectedSince' => microtime(true) - 120.0,
            'lastHeartbeat' => microtime(true) - 60.0, // stale — 60s ago
            'connectionId' => 'stale1111stale111',
        ];
    }

    #[Test]
    public function returnsEmptyWhenFileDoesNotExist(): void
    {
        $observer = new SubscriberObserver($this->jsonPath);
        self::assertSame([], $observer->currentSubscribers());
    }

    #[Test]
    public function returnsActiveRowsAndDropsStaleRows(): void
    {
        $this->writeJson([$this->activeRow(), $this->staleRow()]);

        $observer = new SubscriberObserver($this->jsonPath);
        $rows = $observer->currentSubscribers();

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->accountId);
        self::assertSame('Alice', $rows[0]->accountLabel);
        self::assertSame(['admin'], $rows[0]->channels);
    }

    #[Test]
    public function malformedFileReturnsEmptyArray(): void
    {
        file_put_contents($this->jsonPath, '{not-valid-json}');

        $observer = new SubscriberObserver($this->jsonPath);
        self::assertSame([], $observer->currentSubscribers());
    }

    #[Test]
    public function emptyFileReturnsEmptyArray(): void
    {
        file_put_contents($this->jsonPath, '');

        $observer = new SubscriberObserver($this->jsonPath);
        self::assertSame([], $observer->currentSubscribers());
    }

    #[Test]
    public function anonymousAccountIdZeroReturnsCorrectRow(): void
    {
        $this->writeJson([$this->activeRow(accountId: 0, label: null)]);

        $observer = new SubscriberObserver($this->jsonPath);
        $rows = $observer->currentSubscribers();

        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]->accountId);
        self::assertNull($rows[0]->accountLabel);
    }

    #[Test]
    public function multipleActiveRowsAllReturned(): void
    {
        $this->writeJson([
            $this->activeRow(accountId: 1, label: 'Alice', channels: ['admin']),
            $this->activeRow(accountId: 2, label: 'Bob', channels: ['realtime']),
            $this->activeRow(accountId: 3, label: 'Carol', channels: ['admin', 'realtime']),
        ]);

        $observer = new SubscriberObserver($this->jsonPath);
        $rows = $observer->currentSubscribers();

        self::assertCount(3, $rows);
    }

    #[Test]
    public function atomicRenameDoesNotLeaveCorruptState(): void
    {
        // Simulate concurrent write: write a .tmp file that is renamed over the target
        $tmpFile = $this->jsonPath . '.tmp.' . getmypid();
        $data = [$this->activeRow()];
        file_put_contents($tmpFile, json_encode($data, JSON_THROW_ON_ERROR));
        rename($tmpFile, $this->jsonPath);

        $observer = new SubscriberObserver($this->jsonPath);
        $rows = $observer->currentSubscribers();

        self::assertCount(1, $rows);
        self::assertFileDoesNotExist($tmpFile);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Queue\Attribute\RateLimited;
use Waaseyaa\Queue\Attribute\UniqueJob;
use Waaseyaa\Queue\DbalQueue;
use Waaseyaa\Queue\Job;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Tests\Unit\Fixtures\HighPriorityJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;
use Waaseyaa\Queue\Transport\InMemoryTransport;

#[CoversClass(DbalQueue::class)]
final class DbalQueueTest extends TestCase
{
    private InMemoryTransport $transport;
    private DbalQueue $queue;

    protected function setUp(): void
    {
        $this->transport = new InMemoryTransport();
        $this->queue = new DbalQueue($this->transport, new SignedQueuePayload(str_repeat('q', 32)));
    }

    #[Test]
    public function dispatchSerializesAndPushes(): void
    {
        $this->queue->dispatch(new SuccessfulJob());

        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function dispatchRespectsOnQueueAttribute(): void
    {
        $this->queue->dispatch(new HighPriorityJob());

        self::assertSame(1, $this->transport->size('high'));
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function dispatchAcceptsNonJobMessages(): void
    {
        $message = new \stdClass();
        $message->type = 'test';

        $this->queue->dispatch($message);

        self::assertSame(1, $this->transport->size('default'));
    }

    // --- M2: honest #[UniqueJob] / #[RateLimited] surface ---

    #[Test]
    public function dispatchUniqueJobAttributeLogsWarningAndPushesToTransport(): void
    {
        $warnings = [];
        $logger = $this->makeSpyLogger($warnings);

        $queue = new DbalQueue($this->transport, new SignedQueuePayload(str_repeat('q', 32)), 'default', $logger);
        $queue->dispatch(new DbalQueueUniqueJobFixture());

        // Exactly one warning must be emitted naming the attribute and the class.
        self::assertCount(1, $warnings);
        self::assertStringContainsString('#[UniqueJob]', $warnings[0]);
        self::assertStringContainsString(DbalQueueUniqueJobFixture::class, $warnings[0]);

        // The job MUST still reach the transport — dispatch must not be suppressed.
        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function dispatchUniqueJobTwiceLogsOnlyOnce(): void
    {
        $warnings = [];
        $logger = $this->makeSpyLogger($warnings);

        $queue = new DbalQueue($this->transport, new SignedQueuePayload(str_repeat('q', 32)), 'default', $logger);
        $queue->dispatch(new DbalQueueUniqueJobFixture());
        $queue->dispatch(new DbalQueueUniqueJobFixture());

        // Dedup: same job class must not spam the log.
        self::assertCount(1, $warnings, 'Warning must be logged only once per job class per process');

        // Both jobs still reach the transport.
        self::assertSame(2, $this->transport->size('default'));
    }

    #[Test]
    public function dispatchRateLimitedAttributeLogsWarningAndPushesToTransport(): void
    {
        $warnings = [];
        $logger = $this->makeSpyLogger($warnings);

        $queue = new DbalQueue($this->transport, new SignedQueuePayload(str_repeat('q', 32)), 'default', $logger);
        $queue->dispatch(new DbalQueueRateLimitedFixture());

        self::assertCount(1, $warnings);
        self::assertStringContainsString('#[RateLimited]', $warnings[0]);
        self::assertStringContainsString(DbalQueueRateLimitedFixture::class, $warnings[0]);
        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function dispatchPlainMessageLogsNothing(): void
    {
        $warnings = [];
        $logger = $this->makeSpyLogger($warnings);

        $queue = new DbalQueue($this->transport, new SignedQueuePayload(str_repeat('q', 32)), 'default', $logger);
        $queue->dispatch(new SuccessfulJob());

        self::assertCount(0, $warnings, 'Plain job with no queue attributes must not log any warning');
        self::assertSame(1, $this->transport->size('default'));
    }

    /**
     * Build a spy logger that captures WARNING-level messages into $log.
     *
     * @param list<string> $log Capture array (passed by reference).
     */
    private function makeSpyLogger(array &$log): LoggerInterface
    {
        return new class ($log) implements LoggerInterface {
            use LoggerTrait;

            /** @param list<string> $log */
            public function __construct(private array &$log) {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->log[] = (string) $message;
                }
            }
        };
    }
}

// ---------------------------------------------------------------------------
// Inline test fixtures — defined in the same file to avoid PSR-4 path issues
// when the test suite shares the parent repo's vendor directory (git worktree).
// These classes live in the Waaseyaa\Queue\Tests\Unit namespace (same file).
// ---------------------------------------------------------------------------

#[UniqueJob]
final class DbalQueueUniqueJobFixture extends Job
{
    public function handle(): void {}
}

#[RateLimited]
final class DbalQueueRateLimitedFixture extends Job
{
    public function handle(): void {}
}

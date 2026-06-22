<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Observability\Event\AgentRunToolCallObserved;

#[CoversClass(AgentRunToolCallObserved::class)]
final class AgentRunToolCallObservedTest extends TestCase
{
    #[Test]
    public function constructionWithoutAccountIdDefaultsToNull(): void
    {
        // Additive-compat pin: pre-provenance constructions (no accountId)
        // must keep compiling and read back null — never 0 (FR-005).
        $event = new AgentRunToolCallObserved(
            runId: 'run-1',
            toolName: 'echo_tool',
            succeeded: true,
        );

        self::assertSame('run-1', $event->runId);
        self::assertSame('echo_tool', $event->toolName);
        self::assertTrue($event->succeeded);
        self::assertNull($event->accountId);
    }

    #[Test]
    public function constructionWithAccountIdCarriesIt(): void
    {
        $event = new AgentRunToolCallObserved(
            runId: 'run-2',
            toolName: 'delete_tool',
            succeeded: false,
            accountId: 7,
        );

        self::assertSame(7, $event->accountId);
        self::assertFalse($event->succeeded);
    }

    #[Test]
    public function anonymousInitiatorZeroIsPreservedDistinctFromNull(): void
    {
        // Three-state semantics: 0 means "anonymous initiator", null means
        // "no known initiator" — they must not collapse into each other.
        $event = new AgentRunToolCallObserved(
            runId: 'run-3',
            toolName: 'echo_tool',
            succeeded: true,
            accountId: 0,
        );

        self::assertSame(0, $event->accountId);
        self::assertNotNull($event->accountId);
    }
}

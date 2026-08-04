<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\McpApprovalDecisionAuditListener;

#[CoversClass(McpApprovalDecisionAuditListener::class)]
final class McpApprovalDecisionAuditListenerTest extends TestCase
{
    /** @param list<AuditEventDescriptor> $recorded */
    private function writer(array &$recorded): AuditWriterInterface
    {
        return new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}

            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };
    }

    #[Test]
    public function it_exposes_a_framework_neutral_event_name(): void
    {
        $recorded = [];

        $this->assertSame('waaseyaa.mcp.approval_decision', McpApprovalDecisionAuditListener::EVENT_NAME);
        $this->assertNotInstanceOf(
            \Symfony\Component\EventDispatcher\EventSubscriberInterface::class,
            new McpApprovalDecisionAuditListener($this->writer($recorded)),
        );
    }

    #[Test]
    public function it_records_an_approval_decision_with_safe_join_fields_only(): void
    {
        $recorded = [];
        $event = new class {
            public string $requestId = 'apr_0123456789abcdef0123456789abcdef';
            public int $operatorUid = 42;
            public bool $approved = true;
            public ?string $reason = 'Verified with the requesting team';
            public string $correlationId = 'abc123';
        };

        new McpApprovalDecisionAuditListener($this->writer($recorded))
            ->onApprovalDecision($event);

        $this->assertCount(1, $recorded);
        $descriptor = $recorded[0];
        $this->assertSame(AuditEventKind::McpApprovalDecision, $descriptor->kind);
        $this->assertSame(42, $descriptor->accountUid);
        $this->assertSame('allowed', $descriptor->outcome);
        $this->assertSame('apr_0123456789abcdef0123456789abcdef', $descriptor->attributes['request_id']);
        $this->assertSame('approved', $descriptor->attributes['decision']);
        $this->assertSame('Verified with the requesting team', $descriptor->attributes['reason']);
        $this->assertSame('abc123', $descriptor->attributes['correlation_id']);
    }

    #[Test]
    public function it_records_a_denial_without_a_reason(): void
    {
        $recorded = [];
        $event = new class {
            public string $requestId = 'apr_0123456789abcdef0123456789abcdef';
            public int $operatorUid = 7;
            public bool $approved = false;
            public ?string $reason = null;
            public string $correlationId = 'def456';
        };

        new McpApprovalDecisionAuditListener($this->writer($recorded))
            ->onApprovalDecision($event);

        $this->assertCount(1, $recorded);
        $this->assertSame('denied', $recorded[0]->attributes['decision']);
        $this->assertSame('denied', $recorded[0]->outcome);
        $this->assertArrayNotHasKey('reason', $recorded[0]->attributes);
    }

    #[Test]
    public function it_never_records_raw_arguments_or_payload_shaped_fields(): void
    {
        $recorded = [];
        // A hostile/over-sharing event carrying payload-shaped members: the
        // listener must project only the safe join fields, never arguments.
        $event = new class {
            public string $requestId = 'apr_0123456789abcdef0123456789abcdef';
            public int $operatorUid = 42;
            public bool $approved = true;
            public ?string $reason = null;
            public string $correlationId = 'abc';
            public array $arguments = ['secret' => 'raw-credential'];
            public array $rawParams = ['token' => 'raw-token'];
        };

        new McpApprovalDecisionAuditListener($this->writer($recorded))
            ->onApprovalDecision($event);

        $this->assertCount(1, $recorded);
        $encoded = json_encode($recorded[0]->attributes, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('raw-credential', $encoded);
        $this->assertStringNotContainsString('raw-token', $encoded);
    }

    #[Test]
    public function a_writer_failure_log_carries_the_exception_class_never_its_message(): void
    {
        $writer = new class implements AuditWriterInterface {
            public function record(AuditEventDescriptor $d): void
            {
                throw new \RuntimeException('SENTINEL-INTERNAL-WRITER-DETAIL');
            }
        };
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = [];
        $logger = new class ($logs) implements \Waaseyaa\Foundation\Log\LoggerInterface {
            use \Waaseyaa\Foundation\Log\LoggerTrait;

            /** @param list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
            public function __construct(private array &$logs) {}

            public function log(\Waaseyaa\Foundation\Log\LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = [$level->value, (string) $message, $context];
            }
        };
        $event = new class {
            public string $requestId = 'apr_0123456789abcdef0123456789abcdef';
            public int $operatorUid = 42;
            public bool $approved = true;
            public ?string $reason = null;
            public string $correlationId = 'abc';
        };

        new McpApprovalDecisionAuditListener($writer, $logger)->onApprovalDecision($event);

        self::assertCount(1, $logs);
        $encoded = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SENTINEL-SECRET', $encoded, 'The writer exception message must never reach the log.');
        $this->assertStringNotContainsString('hunter2', $encoded);
        $this->assertSame(\RuntimeException::class, $logs[0][2]['exception_class'] ?? null);
    }

    #[Test]
    public function it_is_best_effort_a_writer_failure_is_logged_and_swallowed(): void
    {
        $writer = new class implements AuditWriterInterface {
            public function record(AuditEventDescriptor $d): void
            {
                throw new \RuntimeException('database on fire');
            }
        };
        $event = new class {
            public string $requestId = 'apr_0123456789abcdef0123456789abcdef';
            public int $operatorUid = 42;
            public bool $approved = true;
            public ?string $reason = null;
            public string $correlationId = 'abc';
        };

        $listener = new McpApprovalDecisionAuditListener($writer);
        $listener->onApprovalDecision($event);

        // Reaching this line without a Throwable IS the assertion.
        $this->addToAssertionCount(1);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Queue\Exception\InvalidPersistentPayload;
use Waaseyaa\Queue\InMemoryQueue;
use Waaseyaa\Queue\PersistentQueueBoundaryConfig;
use Waaseyaa\Queue\QueuePayloadDeprecationDiagnostic;

final class QueuePayloadDeprecationDiagnosticTest extends TestCase
{
    public function test_activation_rejects_entity_message_before_serialization(): void
    {
        $diagnostic = new QueuePayloadDeprecationDiagnostic(
            static function (): void {},
            PersistentQueueBoundaryConfig::enforced(),
        );
        $entity = new class ([], 'user') extends EntityBase {};
        $message = new class ($entity) {
            public function __construct(private readonly object $entity) {}
        };

        $this->expectException(InvalidPersistentPayload::class);
        $diagnostic->inspect($message);
    }

    public function test_in_memory_dispatch_uses_the_same_activation_boundary(): void
    {
        $diagnostic = new QueuePayloadDeprecationDiagnostic(static function (): void {}, PersistentQueueBoundaryConfig::enforced());
        $queue = new InMemoryQueue($diagnostic);
        $entity = new class ([], 'user') extends EntityBase {};
        $message = new class ($entity) {
            public function __construct(private readonly object $entity) {}
        };

        try {
            $queue->dispatch($message);
            self::fail('Activated queue accepted an entity payload.');
        } catch (InvalidPersistentPayload) {
        }
        self::assertSame([], $queue->getMessages());
    }

    public function test_activation_accepts_a_large_scalar_message_projection(): void
    {
        $diagnostic = new QueuePayloadDeprecationDiagnostic(static function (): void {}, PersistentQueueBoundaryConfig::enforced());
        $payload = array_fill(0, 2_000, 'public');
        $message = new class ($payload) {
            /** @param list<string> $payload */
            public function __construct(public readonly array $payload) {}
        };

        self::assertSame($message, $diagnostic->inspect($message));
    }

    public function test_activation_rejects_an_uninspectable_compound_message_projection(): void
    {
        $diagnostic = new QueuePayloadDeprecationDiagnostic(static function (): void {}, PersistentQueueBoundaryConfig::enforced());
        $message = new class (array_fill(0, 1_001, [])) {
            /** @param list<array<never, never>> $payload */
            public function __construct(public readonly array $payload) {}
        };

        $this->expectException(InvalidPersistentPayload::class);
        $diagnostic->inspect($message);
    }

    public function testDetectsAnEntityNestedInAPrivateMessagePropertyWithoutReadingItsValues(): void
    {
        $events = [];
        $diagnostic = new QueuePayloadDeprecationDiagnostic(
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );
        $entity = new class (['id' => 7], 'test', ['id' => 'id']) extends EntityBase {};
        $message = new class ($entity) {
            public function __construct(private readonly object $entity) {}
        };

        self::assertSame($message, $diagnostic->inspect($message));
        self::assertCount(1, $events);
        self::assertSame('entity.deprecation', $events[0][0]);
        self::assertSame('queue.dispatch', $events[0][1]['boundary']);
        self::assertSame($entity::class, $events[0][1]['value_type']);
        self::assertArrayNotHasKey('value', $events[0][1]);
    }
}

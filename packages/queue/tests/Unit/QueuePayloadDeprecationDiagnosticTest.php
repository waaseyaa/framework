<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Queue\QueuePayloadDeprecationDiagnostic;

final class QueuePayloadDeprecationDiagnosticTest extends TestCase
{
    public function testDetectsAnEntityNestedInAPrivateMessagePropertyWithoutReadingItsValues(): void
    {
        $events = [];
        $diagnostic = new QueuePayloadDeprecationDiagnostic(
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );
        $entity = new class(['id' => 7], 'test', ['id' => 'id']) extends EntityBase {};
        $message = new class($entity) {
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

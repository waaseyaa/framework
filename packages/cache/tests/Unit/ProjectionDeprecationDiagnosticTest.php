<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\Cache\ProjectionDeprecationDiagnostic;
use Waaseyaa\Entity\EntityBase;

final class ProjectionDeprecationDiagnosticTest extends TestCase
{
    #[Test]
    public function diagnosticIsDeduplicatedAndDoesNotRejectTheDormantWrite(): void
    {
        $events = [];
        $diagnostic = new ProjectionDeprecationDiagnostic(
            static fn(mixed $value): bool => is_object($value),
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );

        $first = new \stdClass();
        $second = new \stdClass();
        self::assertSame($first, $diagnostic->inspect('render:1', $first));
        self::assertSame($second, $diagnostic->inspect('render:2', $second));
        self::assertCount(1, $events);
        self::assertSame('entity.deprecation', $events[0][0]);
    }

    #[Test]
    public function memoryBackendRunsTheDiagnosticAtItsRealWriteBoundary(): void
    {
        $events = [];
        $diagnostic = new ProjectionDeprecationDiagnostic(
            static fn(mixed $value): bool => is_object($value),
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );
        $backend = new MemoryBackend($diagnostic);
        $value = new \stdClass();

        $backend->set('one', $value);
        $backend->set('two', $value);

        self::assertSame($value, $backend->get('one')->data);
        self::assertCount(1, $events);
    }

    #[Test]
    public function databaseBackendAndFactoryRunTheSharedDiagnostic(): void
    {
        $events = [];
        $diagnostic = new ProjectionDeprecationDiagnostic(
            static fn(mixed $value): bool => is_object($value),
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );
        $value = new \stdClass();
        $database = new DatabaseBackend(new \PDO('sqlite::memory:'), 'cache_projection', projectionDiagnostic: $diagnostic);
        $database->set('database', $value);
        $factory = new CacheFactory(projectionDiagnostic: $diagnostic);
        $factory->get('memory')->set('factory', $value);

        self::assertInstanceOf(\stdClass::class, $database->get('database')->data);
        self::assertSame($value, $factory->get('memory')->get('factory')->data);
        self::assertCount(1, $events);
    }

    #[Test]
    public function entityPayloadDetectorFindsPrivateNestedEntitiesWithoutLoggingValues(): void
    {
        $events = [];
        $diagnostic = ProjectionDeprecationDiagnostic::forEntityPayloads(
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );
        $entity = new class(['id' => 9], 'test', ['id' => 'id']) extends EntityBase {};
        $projection = new class($entity) {
            public function __construct(private readonly object $entity) {}
        };

        self::assertSame($projection, $diagnostic->inspect('private-entity', $projection));
        self::assertCount(1, $events);
        self::assertSame('entity.deprecation', $events[0][0]);
        self::assertSame('cache', $events[0][1]['boundary']);
        self::assertArrayNotHasKey('value', $events[0][1]);
    }
}

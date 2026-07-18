<?php

declare(strict_types=1);

namespace Waaseyaa\State\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\State\EntityPayloadBoundaryConfig;
use Waaseyaa\State\Exception\EntityProjectionWriteForbidden;
use Waaseyaa\State\MemoryState;
use Waaseyaa\State\ProjectionDeprecationDiagnostic;
use Waaseyaa\State\SqlState;

final class ProjectionDeprecationDiagnosticTest extends TestCase
{
    public function test_activation_rejects_nested_entity_before_state_write(): void
    {
        $diagnostic = ProjectionDeprecationDiagnostic::forEntityPayloads(
            static function (): void {},
            EntityPayloadBoundaryConfig::enforced(),
        );
        $state = new MemoryState($diagnostic);
        $entity = new class ([], 'user') extends EntityBase {};

        $this->expectException(EntityProjectionWriteForbidden::class);
        $state->set('current-user', ['entity' => $entity]);
    }

    #[Test]
    public function diagnosticIsDeduplicatedWithoutChangingTheStoredValue(): void
    {
        $events = [];
        $diagnostic = new ProjectionDeprecationDiagnostic(
            static fn(mixed $value): bool => is_object($value),
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );
        $value = new \stdClass();

        self::assertSame($value, $diagnostic->inspect('checkpoint', $value));
        self::assertSame($value, $diagnostic->inspect('checkpoint-next', $value));
        self::assertCount(1, $events);
    }

    #[Test]
    public function memoryStateRunsTheDiagnosticForSetAndSetMultiple(): void
    {
        $events = [];
        $diagnostic = new ProjectionDeprecationDiagnostic(
            static fn(mixed $value): bool => is_object($value),
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );
        $state = new MemoryState($diagnostic);
        $value = new \stdClass();

        $state->set('one', $value);
        $state->setMultiple(['two' => $value]);

        self::assertSame($value, $state->get('two'));
        self::assertCount(1, $events);
    }

    #[Test]
    public function sqlStateRunsTheSharedDiagnosticAtSetAndSetMultiple(): void
    {
        $events = [];
        $diagnostic = new ProjectionDeprecationDiagnostic(
            static fn(mixed $value): bool => is_object($value),
            static function (string $code, array $context) use (&$events): void {
                $events[] = [$code, $context];
            },
        );
        $state = new SqlState(DBALDatabase::createSqlite(), str_repeat('s', 32), $diagnostic);
        $value = new \stdClass();

        $state->set('one', $value);
        $state->setMultiple(['two' => $value]);

        self::assertInstanceOf(\stdClass::class, $state->get('two'));
        self::assertCount(1, $events);
    }
}

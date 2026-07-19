<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendV2Interface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\BackendResolver;
use Waaseyaa\EntityStorage\CoordinatorLifecycleDispatcher;
use Waaseyaa\EntityStorage\EntityStorageCoordinator;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Exception\PartialSaveException;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\EntityStorage\Tests\Support\PermissiveFieldStorageGatewayAudit;
use Waaseyaa\EntityStorage\Tests\Support\V2GatewayTestBackendTrait;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

/**
 * Verifies partial-save exception semantics:
 * - Correct committed/uncommitted partition.
 * - AfterSave does NOT fire on partial failure.
 * - Structured log line emitted with expected fields.
 */
#[CoversClass(EntityStorageCoordinator::class)]
#[CoversClass(CoordinatorLifecycleDispatcher::class)]
#[CoversClass(PartialSaveException::class)]
final class PartialSaveTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * @param FieldStorageBackendV2Interface[] $backends
     */
    private function makeRegistrar(array $backends): BackendRegistrar
    {
        $fqcn = PartialSaveProviderRegistry::register($backends);
        $registrar = new BackendRegistrar([$fqcn], [$fqcn], new PermissiveFieldStorageGatewayAudit());
        $registrar->build();

        return $registrar;
    }

    private function makeCoordinator(
        BackendRegistrar $registrar,
        ?EventDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null,
    ): EntityStorageCoordinator {
        $resolver = new BackendResolver($registrar);

        return new EntityStorageCoordinator($resolver, $registrar, $dispatcher, $logger);
    }

    /**
     * Entity type with two fields, each assigned to a different backend.
     */
    private function makeTwoBackendEntityType(
        string $primaryId,
        string $secondaryId,
    ): EntityType {
        return new EntityType(
            id: 'partial_save_test',
            label: 'Partial Save Test',
            class: PartialSaveTestEntity::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string')->storedIn($primaryId),
                'body'  => new FieldDefinition(name: 'body', type: 'string')->storedIn($secondaryId),
            ],
        );
    }

    // ---------------------------------------------------------------------------
    // Partition tests
    // ---------------------------------------------------------------------------

    #[Test]
    public function partial_save_exception_carries_correct_committed_and_uncommitted(): void
    {
        $primaryBackend = new PartialSaveSpyBackend(ReservedBackendIds::SQL_BLOB);
        $failingBackend = new PartialSaveFailingBackend('alt-backend', new \RuntimeException('alt write failed'));

        $registrar = $this->makeRegistrar([$primaryBackend, $failingBackend]);
        $coordinator = $this->makeCoordinator($registrar);
        $entityType = $this->makeTwoBackendEntityType(ReservedBackendIds::SQL_BLOB, 'alt-backend');
        $entity = new PartialSaveTestEntity(['id' => '1', 'title' => 'hello', 'body' => 'world']);

        $caught = null;

        try {
            $coordinator->write($entity, $entityType);
        } catch (PartialSaveException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'PartialSaveException must be thrown when a backend fails');
        self::assertSame([ReservedBackendIds::SQL_BLOB], $caught->committedBackends, 'Primary backend must appear in committed list');
        self::assertSame(['alt-backend'], $caught->uncommittedBackends, 'Failing backend must appear in uncommitted list');
        self::assertSame('PARTIAL_SAVE', $caught->errorCode);
        self::assertInstanceOf(\RuntimeException::class, $caught->causedBy);
        self::assertSame('alt write failed', $caught->causedBy->getMessage());
        self::assertSame($entity, $caught->entity);
    }

    #[Test]
    public function partial_save_when_primary_fails_has_empty_committed(): void
    {
        $failingPrimary = new PartialSaveFailingBackend(ReservedBackendIds::SQL_BLOB, new \RuntimeException('primary failed'));
        $secondaryBackend = new PartialSaveSpyBackend('alt-backend');

        $registrar = $this->makeRegistrar([$failingPrimary, $secondaryBackend]);
        $coordinator = $this->makeCoordinator($registrar);
        $entityType = $this->makeTwoBackendEntityType(ReservedBackendIds::SQL_BLOB, 'alt-backend');
        $entity = new PartialSaveTestEntity(['id' => '1', 'title' => 'hello', 'body' => 'world']);

        $caught = null;

        try {
            $coordinator->write($entity, $entityType);
        } catch (PartialSaveException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertSame([], $caught->committedBackends, 'No backend committed if primary fails first');
        self::assertContains(ReservedBackendIds::SQL_BLOB, $caught->uncommittedBackends);
        self::assertContains('alt-backend', $caught->uncommittedBackends);
    }

    // ---------------------------------------------------------------------------
    // AfterSave must NOT fire on partial failure
    // ---------------------------------------------------------------------------

    #[Test]
    public function after_save_does_not_fire_on_partial_failure(): void
    {
        $afterSaveFired = false;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AfterSaveEvent::class, function () use (&$afterSaveFired): void {
            $afterSaveFired = true;
        });

        $primaryBackend = new PartialSaveSpyBackend(ReservedBackendIds::SQL_BLOB);
        $failingBackend = new PartialSaveFailingBackend('alt-backend', new \RuntimeException('alt write failed'));

        $registrar = $this->makeRegistrar([$primaryBackend, $failingBackend]);
        $coordinator = $this->makeCoordinator($registrar, $dispatcher);
        $entityType = $this->makeTwoBackendEntityType(ReservedBackendIds::SQL_BLOB, 'alt-backend');
        $entity = new PartialSaveTestEntity(['id' => '1', 'title' => 'hello', 'body' => 'world']);

        try {
            $coordinator->write($entity, $entityType);
        } catch (PartialSaveException) {
            // expected
        }

        self::assertFalse($afterSaveFired, 'AfterSaveEvent MUST NOT fire when PartialSaveException is thrown');
    }

    // ---------------------------------------------------------------------------
    // Structured log line
    // ---------------------------------------------------------------------------

    #[Test]
    public function partial_save_emits_structured_log_line(): void
    {
        $logRecords = [];
        $logger = new class ($logRecords) implements LoggerInterface {
            public function __construct(private array &$records) {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }

            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::EMERGENCY, $message, $context);
            }
            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::ALERT, $message, $context);
            }
            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::CRITICAL, $message, $context);
            }
            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::ERROR, $message, $context);
            }
            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::WARNING, $message, $context);
            }
            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::NOTICE, $message, $context);
            }
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::INFO, $message, $context);
            }
            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->log(LogLevel::DEBUG, $message, $context);
            }
        };

        $primaryBackend = new PartialSaveSpyBackend(ReservedBackendIds::SQL_BLOB);
        $failingBackend = new PartialSaveFailingBackend('alt-backend', new \RuntimeException('alt write failed'));

        $registrar = $this->makeRegistrar([$primaryBackend, $failingBackend]);
        $coordinator = $this->makeCoordinator($registrar, null, $logger);
        $entityType = $this->makeTwoBackendEntityType(ReservedBackendIds::SQL_BLOB, 'alt-backend');
        $entity = new PartialSaveTestEntity(['id' => '99', 'title' => 'hello', 'body' => 'world']);

        try {
            $coordinator->write($entity, $entityType);
        } catch (PartialSaveException) {
            // expected
        }

        self::assertNotEmpty($logRecords, 'A log record must be emitted on partial save');

        $record = $logRecords[0];

        self::assertSame(LogLevel::ERROR, $record['level']);
        self::assertSame('partial_save', $record['context']['outcome']);
        self::assertSame('partial_save_test', $record['context']['entity_type_id']);
        self::assertSame('99', (string) $record['context']['entity_id']);
        self::assertContains(ReservedBackendIds::SQL_BLOB, $record['context']['committed_backends']);
        self::assertContains('alt-backend', $record['context']['uncommitted_backends']);
        self::assertSame(\RuntimeException::class, $record['context']['cause_class']);
        self::assertSame('alt write failed', $record['context']['cause_message']);
        self::assertArrayHasKey('duration_ms', $record['context']);
    }
}

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

/**
 * @internal Test fixture: registry for dynamic anonymous provider classes.
 */
final class PartialSaveProviderRegistry
{
    /** @var array<int, FieldStorageBackendV2Interface[]> */
    private static array $registry = [];

    private static int $counter = 0;

    /**
     * @param FieldStorageBackendV2Interface[] $backends
     * @return class-string
     */
    public static function register(array $backends): string
    {
        self::$counter++;
        $suffix = self::$counter;
        self::$registry[$suffix] = $backends;

        $fqcn = 'PartialSaveTestProvider' . $suffix;

        eval(sprintf(
            'use Waaseyaa\EntityStorage\Backend\HasFieldStorageBackendsV2Interface;
             use Waaseyaa\EntityStorage\Backend\IsFrameworkBackendProviderV2Interface;
             final class %s implements HasFieldStorageBackendsV2Interface, IsFrameworkBackendProviderV2Interface {
                 public function fieldStorageBackendsV2(): array {
                     return \Waaseyaa\EntityStorage\Tests\Integration\Events\PartialSaveProviderRegistry::get(%d);
                 }
             }',
            $fqcn,
            $suffix,
        ));

        return $fqcn;
    }

    /** @return FieldStorageBackendV2Interface[] */
    public static function get(int $suffix): array
    {
        return self::$registry[$suffix] ?? [];
    }
}

/**
 * @internal Test fixture: records writes, succeeds.
 */
final class PartialSaveSpyBackend implements FieldStorageBackendV2Interface
{
    use V2GatewayTestBackendTrait;

    public int $writeCount = 0;

    public function __construct(private readonly string $backendId) {}

    public function id(): string
    {
        return $this->backendId;
    }

    public function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        return null;
    }

    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void
    {
        $this->writeCount++;
    }

    public function delete(EntityInterface $entity): void {}

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }
}

/**
 * @internal Test fixture: always throws on write/delete.
 */
final class PartialSaveFailingBackend implements FieldStorageBackendV2Interface
{
    use V2GatewayTestBackendTrait;

    public function __construct(
        private readonly string $backendId,
        private readonly \Throwable $toThrow,
    ) {}

    public function id(): string
    {
        return $this->backendId;
    }

    public function read(EntityInterface $entity, FieldDefinition $field): mixed
    {
        return null;
    }

    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void
    {
        throw $this->toThrow;
    }

    public function delete(EntityInterface $entity): void
    {
        throw $this->toThrow;
    }

    public function supportsQuery(FieldDefinition $field, EntityQuery $query): bool
    {
        return false;
    }
}

/**
 * @internal Test fixture entity.
 */
final class PartialSaveTestEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'partial_save_test', ['id' => 'id']);
    }
}

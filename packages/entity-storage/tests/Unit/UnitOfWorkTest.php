<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\Event;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Database\TransactionCompletionInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\EntityStorage\UnitOfWork;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(UnitOfWork::class)]
final class UnitOfWorkTest extends TestCase
{
    private DBALDatabase $database;
    private EventDispatcher $eventDispatcher;
    private UnitOfWork $unitOfWork;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->eventDispatcher = new EventDispatcher();
        $this->unitOfWork = new UnitOfWork($this->database, $this->eventDispatcher);

        // Create a test table.
        $entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTable();
    }

    #[Test]
    public function transactionCommitsOnSuccess(): void
    {
        $result = $this->unitOfWork->transaction(function () {
            $this->database->insert('test_entity')
                ->fields(['uuid', 'label', 'bundle', 'langcode', '_data'])
                ->values([
                    'uuid' => 'test-uuid',
                    'label' => 'Committed',
                    'bundle' => 'article',
                    'langcode' => 'en',
                    '_data' => '{}',
                ])
                ->execute();

            return 'success';
        });

        $this->assertSame('success', $result);

        // Verify data was committed.
        $rows = $this->database->select('test_entity')
            ->fields('test_entity')
            ->condition('uuid', 'test-uuid')
            ->execute();

        $found = false;
        foreach ($rows as $row) {
            $row = (array) $row;
            $this->assertSame('Committed', $row['label']);
            $found = true;
        }

        $this->assertTrue($found, 'Row should be found after commit.');
    }

    #[Test]
    public function transactionRollsBackOnException(): void
    {
        try {
            $this->unitOfWork->transaction(function () {
                $this->database->insert('test_entity')
                    ->fields(['uuid', 'label', 'bundle', 'langcode', '_data'])
                    ->values([
                        'uuid' => 'rollback-uuid',
                        'label' => 'Should Not Persist',
                        'bundle' => 'article',
                        'langcode' => 'en',
                        '_data' => '{}',
                    ])
                    ->execute();

                throw new \RuntimeException('Deliberate failure');
            });
            $this->fail('Exception should have been re-thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate failure', $e->getMessage());
        }

        // Verify data was NOT committed.
        $rows = $this->database->select('test_entity')
            ->fields('test_entity')
            ->condition('uuid', 'rollback-uuid')
            ->execute();

        $rowCount = 0;
        foreach ($rows as $row) {
            $rowCount++;
        }

        $this->assertSame(0, $rowCount, 'Row should not exist after rollback.');
    }

    #[Test]
    public function buffersEventsAndDispatchesAfterCommit(): void
    {
        $dispatched = [];

        $this->eventDispatcher->addListener(
            'test.event',
            function (Event $event) use (&$dispatched) {
                $dispatched[] = 'test.event';
            },
        );

        $this->unitOfWork->transaction(function () {
            $this->unitOfWork->bufferEvent(new Event(), 'test.event');
        });

        // After commit, buffered events should be dispatched.
        $this->assertSame(['test.event'], $dispatched);
    }

    #[Test]
    public function bufferedEventsDiscardedOnRollback(): void
    {
        $dispatched = [];

        $this->eventDispatcher->addListener(
            'test.event',
            function (Event $event) use (&$dispatched) {
                $dispatched[] = 'test.event';
            },
        );

        try {
            $this->unitOfWork->transaction(function () {
                $this->unitOfWork->bufferEvent(new Event(), 'test.event');
                throw new \RuntimeException('Fail');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        // Events should NOT have been dispatched.
        $this->assertSame([], $dispatched);
    }

    #[Test]
    public function bufferEventDispatchesImmediatelyOutsideTransaction(): void
    {
        $dispatched = [];

        $this->eventDispatcher->addListener(
            'test.event',
            function (Event $event) use (&$dispatched) {
                $dispatched[] = 'test.event';
            },
        );

        // Not inside a transaction.
        $this->unitOfWork->bufferEvent(new Event(), 'test.event');

        $this->assertSame(['test.event'], $dispatched);
    }

    #[Test]
    public function isInTransaction(): void
    {
        $this->assertFalse($this->unitOfWork->isInTransaction());

        $insideValue = null;

        $this->unitOfWork->transaction(function () use (&$insideValue) {
            $insideValue = $this->unitOfWork->isInTransaction();
        });

        $this->assertTrue($insideValue);
        $this->assertFalse($this->unitOfWork->isInTransaction());
    }

    #[Test]
    public function nestedTransactionRunsCallbackDirectly(): void
    {
        $result = $this->unitOfWork->transaction(function () {
            // Nested transaction.
            return $this->unitOfWork->transaction(function () {
                return 'nested-result';
            });
        });

        $this->assertSame('nested-result', $result);
    }

    #[Test]
    public function multipleBufferedEventsDispatchInOrder(): void
    {
        $dispatched = [];

        $this->eventDispatcher->addListener(
            'event.first',
            function () use (&$dispatched) {
                $dispatched[] = 'first';
            },
        );

        $this->eventDispatcher->addListener(
            'event.second',
            function () use (&$dispatched) {
                $dispatched[] = 'second';
            },
        );

        $this->eventDispatcher->addListener(
            'event.third',
            function () use (&$dispatched) {
                $dispatched[] = 'third';
            },
        );

        $this->unitOfWork->transaction(function () {
            $this->unitOfWork->bufferEvent(new Event(), 'event.first');
            $this->unitOfWork->bufferEvent(new Event(), 'event.second');
            $this->unitOfWork->bufferEvent(new Event(), 'event.third');
        });

        $this->assertSame(['first', 'second', 'third'], $dispatched);
    }

    #[Test]
    public function separateUnitOfWorkDefersCompletionUntilManagedOuterCommit(): void
    {
        $observedDepths = [];
        $afterCommitRan = false;
        $this->eventDispatcher->addListener(
            'test.event',
            function () use (&$observedDepths): void {
                $observedDepths[] = $this->database->getConnection()->getTransactionNestingLevel();
            },
        );

        $outer = $this->database->transaction();
        $inner = new UnitOfWork($this->database, $this->eventDispatcher);
        $inner->transaction(function () use ($inner, &$afterCommitRan): void {
            $this->database->insert('test_entity')
                ->fields(['uuid', 'label', 'bundle', 'langcode', '_data'])
                ->values([
                    'uuid' => 'nested-commit',
                    'label' => 'Nested commit',
                    'bundle' => 'article',
                    'langcode' => 'en',
                    '_data' => '{}',
                ])
                ->execute();
            $inner->afterCommit(static function () use (&$afterCommitRan): void {
                $afterCommitRan = true;
            });
            $inner->bufferEvent(new Event(), 'test.event');
        });

        $this->assertFalse($afterCommitRan);
        $this->assertSame([], $observedDepths);

        $outer->commit();

        $this->assertTrue($afterCommitRan);
        $this->assertSame([0], $observedDepths);
    }

    #[Test]
    public function separateUnitOfWorkDiscardsCompletionOnManagedOuterRollback(): void
    {
        $dispatched = [];
        $afterCommitRan = false;
        $this->eventDispatcher->addListener('test.event', static function () use (&$dispatched): void {
            $dispatched[] = 'test.event';
        });

        $outer = $this->database->transaction();
        $inner = new UnitOfWork($this->database, $this->eventDispatcher);
        $inner->transaction(function () use ($inner, &$afterCommitRan): void {
            $this->database->insert('test_entity')
                ->fields(['uuid', 'label', 'bundle', 'langcode', '_data'])
                ->values([
                    'uuid' => 'nested-rollback',
                    'label' => 'Nested rollback',
                    'bundle' => 'article',
                    'langcode' => 'en',
                    '_data' => '{}',
                ])
                ->execute();
            $inner->afterCommit(static function () use (&$afterCommitRan): void {
                $afterCommitRan = true;
            });
            $inner->bufferEvent(new Event(), 'test.event');
        });

        $outer->rollBack();

        $this->assertFalse($afterCommitRan);
        $this->assertSame([], $dispatched);
        $this->assertSame(0, (int) $this->database->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM test_entity WHERE uuid = 'nested-rollback'",
        ));
    }

    #[Test]
    public function throwingEventDoesNotStarveLaterEvents(): void
    {
        $records = [];
        $logger = new class ($records) implements LoggerInterface {
            use LoggerTrait;

            /** @param list<array{level: LogLevel, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records) {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $dispatched = [];
        $this->eventDispatcher->addListener('event.throwing', static function (): never {
            throw new class ('listener failed') extends \RuntimeException {};
        });
        $this->eventDispatcher->addListener('event.later', static function () use (&$dispatched): void {
            $dispatched[] = 'later';
        });
        $unitOfWork = new UnitOfWork($this->database, $this->eventDispatcher, $logger);

        try {
            $unitOfWork->transaction(function () use ($unitOfWork): void {
                $unitOfWork->bufferEvent(new class extends Event {}, 'event.throwing');
                $unitOfWork->bufferEvent(new Event(), 'event.later');
            });
            self::fail('The listener failure must be surfaced after the complete event drain.');
        } catch (TransactionCompletionException $failure) {
            self::assertSame(1, count($failure->failures()));
        }

        $this->assertSame(['later'], $dispatched);
        $this->assertCount(1, $records);
        $this->assertSame(LogLevel::ERROR, $records[0]['level']);
        $this->assertSame('anonymous', $records[0]['context']['failure_class']);
        $this->assertSame('anonymous', $records[0]['context']['event_class']);
        $this->assertArrayNotHasKey('event_name', $records[0]['context']);
    }

    #[Test]
    public function requiredAfterCommitFailureContinuesDrainAndSurfacesCommittedOutcome(): void
    {
        $dispatched = [];
        $this->eventDispatcher->addListener('event.throwing', static function (): never {
            throw new \RuntimeException('event failed');
        });
        $this->eventDispatcher->addListener('event.later', static function () use (&$dispatched): void {
            $dispatched[] = 'event-later';
        });

        try {
            $this->unitOfWork->transaction(function () use (&$dispatched): void {
                $this->unitOfWork->afterCommit(static function (): never {
                    throw new \RuntimeException('callback failed');
                });
                $this->unitOfWork->afterCommit(static function () use (&$dispatched): void {
                    $dispatched[] = 'callback-later';
                });
                $this->unitOfWork->bufferEvent(new Event(), 'event.throwing');
                $this->unitOfWork->bufferEvent(new Event(), 'event.later');
            });
            self::fail('A required after-commit failure must be surfaced as a committed completion failure.');
        } catch (TransactionCompletionException $failure) {
            self::assertSame(2, count($failure->failures()));
        }

        self::assertSame(['callback-later', 'event-later'], $dispatched);
    }

    #[Test]
    public function failingDrainDoesNotStarveAnotherUnitOfWorkOnTheSameOuterTransaction(): void
    {
        $dispatched = [];
        $this->eventDispatcher->addListener('event.first', static function (): never {
            throw new \RuntimeException('first repository listener failed');
        });
        $this->eventDispatcher->addListener('event.second', static function () use (&$dispatched): void {
            $dispatched[] = 'second';
        });
        $outer = $this->database->transaction();

        $first = new UnitOfWork($this->database, $this->eventDispatcher);
        $first->transaction(static function () use ($first): void {
            $first->bufferEvent(new Event(), 'event.first');
        });
        $second = new UnitOfWork($this->database, $this->eventDispatcher);
        $second->transaction(static function () use ($second): void {
            $second->bufferEvent(new Event(), 'event.second');
        });
        self::assertSame([], $dispatched);

        try {
            $outer->commit();
            self::fail('The first drain failure must surface after every outer callback runs.');
        } catch (TransactionCompletionException $failure) {
            self::assertSame(1, count($failure->failures()));
        }

        self::assertSame(['second'], $dispatched);
    }

    #[Test]
    public function transactionRefusesACompletionBlindDatabaseBoundary(): void
    {
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->expects(self::once())->method('rollBack');
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('transaction')->willReturn($transaction);
        $unitOfWork = new UnitOfWork($database, $this->eventDispatcher);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires a transaction that implements');
        $unitOfWork->transaction(static function (): void {});
    }

    #[Test]
    public function failedDatabaseCommitRollsBackTheManagedBoundary(): void
    {
        $transaction = $this->createMock(TransactionCompletionInterface::class);
        $transaction->expects(self::once())->method('afterCommit');
        $transaction->expects(self::once())->method('commit')->willThrowException(new \RuntimeException('commit failed'));
        $transaction->expects(self::once())->method('rollBack');
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('transaction')->willReturn($transaction);
        $unitOfWork = new UnitOfWork($database, $this->eventDispatcher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('commit failed');
        $unitOfWork->transaction(static function (): void {});
    }

    #[Test]
    public function loggerFailureCannotHideOrStarveCompletionFailures(): void
    {
        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): never
            {
                throw new class ('logger failed') extends \RuntimeException {};
            }
        };
        $this->eventDispatcher->addListener('event.throwing', static function (): never {
            throw new class ('listener failed') extends \RuntimeException {};
        });
        $unitOfWork = new UnitOfWork($this->database, $this->eventDispatcher, $logger);

        try {
            $unitOfWork->transaction(static function () use ($unitOfWork): void {
                $unitOfWork->bufferEvent(new class extends Event {}, 'event.throwing');
            });
            self::fail('The listener failure must remain visible when logging also fails.');
        } catch (TransactionCompletionException $failure) {
            self::assertCount(1, $failure->failures());
            self::assertSame('listener failed', $failure->failures()[0]->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Tests\Unit;

use Aurora\Database\PdoDatabase;
use Aurora\Entity\EntityType;
use Aurora\EntityStorage\SqlSchemaHandler;
use Aurora\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Aurora\EntityStorage\UnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\Event;

#[CoversClass(UnitOfWork::class)]
final class UnitOfWorkTest extends TestCase
{
    private PdoDatabase $database;
    private EventDispatcher $eventDispatcher;
    private UnitOfWork $unitOfWork;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();
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
}

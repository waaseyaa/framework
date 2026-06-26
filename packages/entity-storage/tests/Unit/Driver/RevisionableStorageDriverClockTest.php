<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Driver;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

#[CoversClass(RevisionableStorageDriver::class)]
final class RevisionableStorageDriverClockTest extends TestCase
{
    #[Test]
    public function write_revision_uses_injected_clock_for_revision_created(): void
    {
        $db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test Revisionable',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $fixedClock = new class implements EntityClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2020-01-02 03:04:05', new DateTimeZone('UTC'));
            }
        };

        $resolver = new SingleConnectionResolver($db);
        $driver = new RevisionableStorageDriver($resolver, $entityType, $fixedClock);

        $driver->writeRevision('1', ['title' => 'Hello', 'uuid' => 'a'], 'log');

        $row = $driver->readRevision('1', 1);

        self::assertNotNull($row);
        self::assertSame('2020-01-02 03:04:05', $row['revision_created']);
    }
}

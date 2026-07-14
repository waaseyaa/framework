<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\Tests\Unit;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Messaging\Schema\ThreadParticipantSchema;
use Waaseyaa\Messaging\ThreadParticipant;

#[CoversClass(ThreadParticipantSchema::class)]
final class ThreadParticipantSchemaTest extends TestCase
{
    #[Test]
    public function generic_table_is_healed_and_thread_user_pair_is_unique(): void
    {
        EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $type = EntityType::fromClass(ThreadParticipant::class, group: 'messaging');
        (new SqlSchemaHandler($type, $database))->ensureTable();

        $repository = new EntityRepository(
            $type,
            new SqlStorageDriver(new SingleConnectionResolver($database), 'tpid'),
            new EventDispatcher(),
            database: $database,
        );
        $first = $repository->create([
            'thread_id' => 42,
            'user_id' => 7,
            'thread_creator_id' => 7,
            'role' => 'owner',
        ]);
        $repository->save($first, validate: false);

        $schema = new ThreadParticipantSchema($database);
        $schema->ensureTable();
        $schema->ensureTable();

        self::assertTrue($database->schema()->fieldExists('thread_participant', 'thread_id'));
        self::assertTrue($database->schema()->fieldExists('thread_participant', 'user_id'));
        $rows = iterator_to_array($database->query(
            'SELECT thread_id, user_id FROM thread_participant WHERE tpid = ?',
            [(string) $first->id()],
        ));
        $row = (array) ($rows[0] ?? []);
        self::assertSame(42, (int) ($row['thread_id'] ?? 0));
        self::assertSame(7, (int) ($row['user_id'] ?? 0));

        $duplicate = $repository->create([
            'thread_id' => 42,
            'user_id' => 7,
            'thread_creator_id' => 7,
            'role' => 'member',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save($duplicate, validate: false);
    }
}

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

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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

    #[Test]
    public function legacy_duplicate_pairs_are_merged_before_the_unique_key_is_added(): void
    {
        EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $type = EntityType::fromClass(ThreadParticipant::class, group: 'messaging');
        (new SqlSchemaHandler($type, $database))->ensureTable();

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver(new SingleConnectionResolver($database), 'tpid'),
            new EventDispatcher(),
            database: $database,
        );
        $older = $repository->create([
            'thread_id' => 42,
            'user_id' => 7,
            'thread_creator_id' => 7,
            'role' => 'member',
            'joined_at' => 100,
            'last_read_at' => 10,
        ]);
        $repository->save($older, validate: false);
        $newer = $repository->create([
            'thread_id' => 42,
            'user_id' => 7,
            'thread_creator_id' => 7,
            'role' => 'owner',
            'joined_at' => 200,
            'last_read_at' => 20,
        ]);
        $repository->save($newer, validate: false);

        (new ThreadParticipantSchema($database))->ensureTable();

        $rows = iterator_to_array($database->query(
            'SELECT tpid, role, _data FROM thread_participant WHERE thread_id = ? AND user_id = ?',
            [42, 7],
        ));
        self::assertCount(1, $rows);
        self::assertSame((string) $older->id(), (string) $rows[0]['tpid']);
        $data = json_decode((string) $rows[0]['_data'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('owner', $rows[0]['role']);
        self::assertSame(100, $data['joined_at']);
        self::assertSame(20, $data['last_read_at']);
    }
}

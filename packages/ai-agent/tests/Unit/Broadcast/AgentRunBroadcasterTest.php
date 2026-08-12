<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Broadcast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcaster;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(AgentRunBroadcaster::class)]
final class AgentRunBroadcasterTest extends TestCase
{
    #[Test]
    public function pushCallsBroadcastStorageWithCorrectChannel(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::broadcast($db);
        $storage = new BroadcastStorage($db);
        $broadcaster = new AgentRunBroadcaster($storage);

        $broadcaster->push(runId: 'abc', event: 'agent.run.started', data: ['key' => 'val']);

        $conn = $db->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT channel, event, data FROM _broadcast_log ORDER BY id ASC',
        );

        self::assertCount(1, $rows);
        self::assertSame('agent.run.abc', $rows[0]['channel']);
        self::assertSame('agent.run.started', $rows[0]['event']);

        $payload = json_decode((string) $rows[0]['data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('abc', $payload['run_id']);
        self::assertSame('val', $payload['key']);
    }

    #[Test]
    public function pushCatchesAndLogsStorageException(): void
    {
        // Use a broken SQLite path to trigger a push failure.
        // We pass a logger spy to confirm the error is logged, not re-thrown.
        $db = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::broadcast($db);
        $storage = new BroadcastStorage($db);

        $logged = [];
        $logger = new class ($logged) implements LoggerInterface {
            /** @param array<int, string> $logged */
            public function __construct(private array &$logged) {}

            public function emergency(string|\Stringable $message, array $context = []): void {}
            public function alert(string|\Stringable $message, array $context = []): void {}
            public function critical(string|\Stringable $message, array $context = []): void {}

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }

            public function warning(string|\Stringable $message, array $context = []): void {}
            public function notice(string|\Stringable $message, array $context = []): void {}
            public function info(string|\Stringable $message, array $context = []): void {}
            public function debug(string|\Stringable $message, array $context = []): void {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void {}
        };

        // Drop the table to force a push() failure without needing to mock BroadcastStorage.
        $db->getConnection()->executeStatement('DROP TABLE _broadcast_log');

        $broadcaster = new AgentRunBroadcaster($storage, $logger);

        // Must NOT throw — best-effort side effect.
        $broadcaster->push(runId: 'xyz', event: 'agent.run.started', data: []);

        self::assertCount(1, $logged);
        self::assertStringContainsString('AgentRunBroadcaster', $logged[0]);
        self::assertStringContainsString('xyz', $logged[0]);
    }
}

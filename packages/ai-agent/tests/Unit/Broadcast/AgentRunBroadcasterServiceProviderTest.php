<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Broadcast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcaster;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterServiceProvider;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

#[CoversClass(AgentRunBroadcasterServiceProvider::class)]
final class AgentRunBroadcasterServiceProviderTest extends TestCase
{
    #[Test]
    public function register_binds_AgentRunBroadcasterInterface_to_AgentRunBroadcaster(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::broadcast($db);

        // Provide DatabaseInterface via KernelServicesInterface (the public injection point).
        $kernelServices = new class ($db) implements KernelServicesInterface {
            public function __construct(private readonly DBALDatabase $db) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->db : null;
            }
        };

        $provider = new AgentRunBroadcasterServiceProvider();
        $provider->setKernelServices($kernelServices);
        $provider->register();

        $result = $provider->resolve(AgentRunBroadcasterInterface::class);

        self::assertNotNull($result);
        self::assertInstanceOf(AgentRunBroadcaster::class, $result);
    }
}

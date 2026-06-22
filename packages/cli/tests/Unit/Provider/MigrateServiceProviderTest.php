<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Handler\MigrateRollbackHandler;
use Waaseyaa\CLI\Handler\MigrateStatusHandler;
use Waaseyaa\CLI\Provider\MigrateServiceProvider;

/**
 * Regression guard for the migrate* command wiring.
 *
 * `MigrateHandler` / `MigrateRollbackHandler` / `MigrateStatusHandler` can NOT
 * be container auto-wired: their `Migrator` dependency's first ctor parameter is
 * a raw `Doctrine\DBAL\Connection` (auto-wire fails with "unresolvable parameter
 * $params"), and each handler additionally requires a `\Closure` migrations
 * provider (a closure can never be reflection-constructed). So the provider MUST
 * bind them explicitly; if it regresses to leaving them to the console handler
 * container's reflection fallback, every `waaseyaa migrate*` invocation dies at
 * command time in every consumer app.
 */
#[CoversClass(MigrateServiceProvider::class)]
final class MigrateServiceProviderTest extends TestCase
{
    #[Test]
    public function it_binds_the_migrate_command_handlers_so_they_are_never_auto_wired(): void
    {
        $provider = new MigrateServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->register();

        $bindings = $provider->getBindings();

        // Each handler is bound (so the handler container resolves it from the
        // provider instead of falling through to the failing reflection path)...
        self::assertArrayHasKey(MigrateHandler::class, $bindings);
        self::assertArrayHasKey(MigrateRollbackHandler::class, $bindings);
        self::assertArrayHasKey(MigrateStatusHandler::class, $bindings);

        // ...as a lazy factory closure, so registering the provider never opens
        // the database (the runtime is built only when a migrate* command runs).
        self::assertIsCallable($bindings[MigrateHandler::class]['concrete']);
        self::assertIsCallable($bindings[MigrateRollbackHandler::class]['concrete']);
        self::assertIsCallable($bindings[MigrateStatusHandler::class]['concrete']);
    }
}

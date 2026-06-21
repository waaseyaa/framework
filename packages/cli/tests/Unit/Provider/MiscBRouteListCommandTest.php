<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Provider\MiscBServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * #1684 — `route:list` crashed in the console: its handler did
 * `$this->resolve(WaaseyaaRouter::class)`, but `WaaseyaaRouter` is built per HTTP
 * request inside `HttpKernel` and never container-bound, so the console raised
 * "No binding registered for ...WaaseyaaRouter". The command now builds a
 * populated router from `BuiltinRouteRegistrar` (the kernel's single source) and
 * lists routes instead of throwing.
 */
#[CoversClass(MiscBServiceProvider::class)]
final class MiscBRouteListCommandTest extends TestCase
{
    #[Test]
    public function route_list_lists_routes_in_the_console_instead_of_crashing(): void
    {
        // A real (empty) EntityTypeManager is all the rebuilt command resolves;
        // BuiltinRouteRegistrar still registers the static builtin routes from it.
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());

        $provider = new MiscBServiceProvider();
        $provider->setKernelServices(new class ($entityTypeManager) implements KernelServicesInterface {
            public function __construct(private readonly EntityTypeManager $etm) {}

            public function get(string $abstract): ?object
            {
                return ($abstract === EntityTypeManagerInterface::class || $abstract === EntityTypeManager::class)
                    ? $this->etm
                    : null;
            }
        });

        $tester = CliTester::for($this->routeListCommand($provider), $this->throwingContainer());
        $tester->executeMap([]);

        // Pre-fix: this threw "No binding registered for ...WaaseyaaRouter".
        self::assertSame(0, $tester->getExitCode());
        // The router is populated from BuiltinRouteRegistrar — a static builtin
        // route is listed, proving it is not the empty "No routes found." router.
        self::assertStringContainsString('/api/openapi.json', $tester->getStdout());
    }

    private function routeListCommand(MiscBServiceProvider $provider): HandlerCommand
    {
        foreach ($provider->consoleCommands() as $command) {
            if ($command instanceof HandlerCommand && $command->name === 'route:list') {
                return $command;
            }
        }

        self::fail('route:list command not found in MiscBServiceProvider::consoleCommands()');
    }

    private function throwingContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Container::get not used in this test');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}

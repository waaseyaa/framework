<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * The console runtime registers zero commands from a provider whose optional
 * package is absent, and every command when it is present (#2826).
 */
#[CoversClass(ConsoleApplicationFactory::class)]
final class OptionalPackageConsoleCommandsTest extends TestCase
{
    #[Test]
    public function a_provider_with_an_absent_optional_package_registers_no_commands(): void
    {
        $application = new ConsoleApplicationFactory(
            kernel: $this->kernel(),
            container: $this->container(),
            providers: [new OptionalCommandsAbsentFixtureProvider(), new CoreCommandFixtureProvider()],
        )->create();

        self::assertFalse($application->has('optional:absent'), 'No command from the gated provider may be advertised.');
        self::assertTrue($application->has('core:lifecycle'), 'Core commands remain available without the optional package.');
        self::assertSame(
            ['core:lifecycle'],
            array_values(array_filter(
                array_keys($application->all()),
                static fn(string $name): bool => !in_array($name, ['help', 'list', 'completion', '_complete'], true),
            )),
        );
    }

    #[Test]
    public function a_provider_with_a_present_optional_package_registers_every_command(): void
    {
        $application = new ConsoleApplicationFactory(
            kernel: $this->kernel(),
            container: $this->container(),
            providers: [new OptionalCommandsPresentFixtureProvider()],
        )->create();

        self::assertTrue($application->has('optional:present'));
        self::assertTrue($application->has('optional:present-too'));
    }

    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): object
            {
                throw new class ($id) extends \RuntimeException implements NotFoundExceptionInterface {
                    public function __construct(string $id)
                    {
                        parent::__construct(sprintf('Not found: %s', $id));
                    }
                };
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    private function kernel(): AbstractKernel
    {
        return new class (sys_get_temp_dir()) extends AbstractKernel {};
    }
}

final class OptionalCommandsAbsentFixtureProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresOptionalPackagesInterface
{
    public static function optionalPackageRequirements(): iterable
    {
        yield new OptionalPackageRequirement('waaseyaa/never-installed', 'Waaseyaa\\Tests\\NeverInstalled\\Sentinel', 'fixture commands');
    }

    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(name: 'optional:absent', description: 'Must never register.', handler: [self::class, 'execute']);
    }
}

final class OptionalCommandsPresentFixtureProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresOptionalPackagesInterface
{
    public static function optionalPackageRequirements(): iterable
    {
        yield new OptionalPackageRequirement('waaseyaa/foundation', ServiceProvider::class, 'fixture commands');
    }

    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(name: 'optional:present', description: 'Registers when present.', handler: [self::class, 'execute']);
        yield new HandlerCommand(name: 'optional:present-too', description: 'Registers when present.', handler: [self::class, 'execute']);
    }
}

final class CoreCommandFixtureProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(name: 'core:lifecycle', description: 'Always available.', handler: [self::class, 'execute']);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Command\Command;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\Config\Exception\ConfigCommandCollisionException;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

#[CoversNothing]
final class ProviderRegistersCommandsTest extends TestCase
{
    #[Test]
    public function providerCommandsAreRegisteredInSymfonyApplication(): void
    {
        $provider = new class extends ServiceProvider implements ProvidesConsoleCommandsInterface {
            public function register(): void {}

            public function consoleCommands(): iterable
            {
                yield new HandlerCommand(
                    name: 'cmd-a',
                    description: 'Command A.',
                    handler: static fn(SymfonyCommandIO $io): int => 0,
                );
                yield new HandlerCommand(
                    name: 'cmd-b',
                    description: 'Command B.',
                    handler: static fn(SymfonyCommandIO $io): int => 0,
                );
            }
        };

        $application = $this->factory([$provider])->create();

        self::assertTrue($application->has('cmd-a'));
        self::assertTrue($application->has('cmd-b'));
    }

    #[Test]
    public function duplicateCommandsKeepFirstRegistration(): void
    {
        $first = new NamedProviderCommand('shared-cmd', 'From A.');
        $second = new NamedProviderCommand('shared-cmd', 'From B.');

        $provider = new class([$first, $second]) extends ServiceProvider implements ProvidesConsoleCommandsInterface {
            public function __construct(private readonly array $commands) {}

            public function register(): void {}

            public function consoleCommands(): iterable
            {
                yield from $this->commands;
            }
        };

        $application = $this->factory([$provider])->create();

        self::assertSame('From A.', $application->find('shared-cmd')->getDescription());
    }

    #[Test]
    public function reservedConfigCommandCollisionFailsRegistration(): void
    {
        $provider = new class extends ServiceProvider implements ProvidesConsoleCommandsInterface {
            public function register(): void {}

            public function consoleCommands(): iterable
            {
                yield new NamedProviderCommand('config:export', 'Collision.');
            }
        };

        $this->expectException(ConfigCommandCollisionException::class);

        $this->factory([$provider])->create();
    }

    #[Test]
    public function packageManifestConsoleCommandProvidersRoundTrip(): void
    {
        $manifest = PackageManifest::fromArray([
            'providers' => ['App\\MyProvider'],
            'migrations' => [],
            'field_types' => [],
            'middleware' => [],
            'console_command_providers' => ['App\\MyProvider'],
        ]);

        self::assertSame(['App\\MyProvider'], $manifest->consoleCommandProviders);
        self::assertSame(['App\\MyProvider'], $manifest->toArray()['console_command_providers']);
        self::assertArrayNotHasKey('native_command_providers', $manifest->toArray());
    }

    /**
     * @param list<object> $providers
     */
    private function factory(array $providers): ConsoleApplicationFactory
    {
        return new ConsoleApplicationFactory(
            kernel: new class(sys_get_temp_dir()) extends AbstractKernel {},
            container: $this->container(),
            providers: $providers,
        );
    }

    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new class($id) extends \RuntimeException implements NotFoundExceptionInterface {
                    public function __construct(string $id)
                    {
                        parent::__construct("No entry found for: {$id}");
                    }
                };
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}

final class NamedProviderCommand extends Command
{
    public function __construct(string $name, string $description)
    {
        parent::__construct($name);
        $this->setDescription($description);
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}

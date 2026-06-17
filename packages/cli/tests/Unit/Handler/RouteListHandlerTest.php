<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\RouteListHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(RouteListHandler::class)]
final class RouteListHandlerTest extends TestCase
{
    private function makeDefinition(RouteListHandler $handler): HandlerCommand
    {
        return new HandlerCommand(
            name: 'route:list',
            description: 'List all registered routes',
            options: [
                new HandlerOption(name: 'path', mode: HandlerOptionMode::Required, description: 'Filter routes by path pattern'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
    }

    private function makeContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Container::get not used in unit tests');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function it_lists_registered_routes(): void
    {
        $router = new WaaseyaaRouter();
        $router->addRoute('api.nodes', new Route('/api/node/{id}', methods: ['GET']));
        $router->addRoute('api.nodes.create', new Route('/api/node', methods: ['POST']));

        $handler = new RouteListHandler(router: $router);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        self::assertStringContainsString('GET', $output);
        self::assertStringContainsString('/api/node/{id}', $output);
        self::assertStringContainsString('api.nodes', $output);
        self::assertStringContainsString('POST', $output);
        self::assertStringContainsString('api.nodes.create', $output);
    }

    #[Test]
    public function it_shows_any_for_routes_without_methods(): void
    {
        $router = new WaaseyaaRouter();
        $router->addRoute('catch.all', new Route('/catch'));

        $handler = new RouteListHandler(router: $router);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap([]);

        self::assertStringContainsString('ANY', $tester->getStdout());
    }

    #[Test]
    public function it_filters_routes_by_path(): void
    {
        $router = new WaaseyaaRouter();
        $router->addRoute('api.nodes', new Route('/api/node/{id}', methods: ['GET']));
        $router->addRoute('admin.dashboard', new Route('/admin/dashboard', methods: ['GET']));

        $handler = new RouteListHandler(router: $router);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap(['--path' => '/admin']);

        $output = $tester->getStdout();
        self::assertStringContainsString('/admin/dashboard', $output);
        self::assertStringNotContainsString('/api/node', $output);
    }

    #[Test]
    public function it_shows_message_when_no_routes_found(): void
    {
        $router = new WaaseyaaRouter();

        $handler = new RouteListHandler(router: $router);
        $tester = CliTester::for($this->makeDefinition($handler), $this->makeContainer());
        $tester->executeMap([]);

        self::assertStringContainsString('No routes found.', $tester->getStdout());
    }
}

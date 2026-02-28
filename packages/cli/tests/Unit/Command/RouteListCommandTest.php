<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\RouteListCommand;
use Aurora\Routing\AuroraRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;

#[CoversClass(RouteListCommand::class)]
final class RouteListCommandTest extends TestCase
{
    #[Test]
    public function it_lists_registered_routes(): void
    {
        $router = new AuroraRouter();
        $router->addRoute('api.nodes', new Route('/api/node/{id}', methods: ['GET']));
        $router->addRoute('api.nodes.create', new Route('/api/node', methods: ['POST']));

        $tester = $this->createTester($router);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('GET', $output);
        $this->assertStringContainsString('/api/node/{id}', $output);
        $this->assertStringContainsString('api.nodes', $output);
        $this->assertStringContainsString('POST', $output);
        $this->assertStringContainsString('api.nodes.create', $output);
    }

    #[Test]
    public function it_shows_any_for_routes_without_methods(): void
    {
        $router = new AuroraRouter();
        $router->addRoute('catch.all', new Route('/catch'));

        $tester = $this->createTester($router);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('ANY', $output);
    }

    #[Test]
    public function it_filters_routes_by_path(): void
    {
        $router = new AuroraRouter();
        $router->addRoute('api.nodes', new Route('/api/node/{id}', methods: ['GET']));
        $router->addRoute('admin.dashboard', new Route('/admin/dashboard', methods: ['GET']));

        $tester = $this->createTester($router);
        $tester->execute(['--path' => '/admin']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('/admin/dashboard', $output);
        $this->assertStringNotContainsString('/api/node', $output);
    }

    #[Test]
    public function it_shows_message_when_no_routes_found(): void
    {
        $router = new AuroraRouter();

        $tester = $this->createTester($router);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('No routes found.', $output);
    }

    private function createTester(AuroraRouter $router): CommandTester
    {
        $app = new Application();
        $app->add(new RouteListCommand($router));
        $command = $app->find('route:list');

        return new CommandTester($command);
    }
}

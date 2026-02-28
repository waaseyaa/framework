<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Make;

use Aurora\CLI\Command\Make\MakeProviderCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeProviderCommand::class)]
final class MakeProviderCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_service_provider(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'SitemapServiceProvider']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('class SitemapServiceProvider extends ServiceProvider', $output);
        $this->assertStringContainsString('public function register(): void', $output);
        $this->assertStringContainsString('public function boot(): void', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_converts_snake_case_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'sitemap_service_provider']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('class SitemapServiceProvider extends ServiceProvider', $output);
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->add(new MakeProviderCommand());
        $command = $app->find('make:provider');

        return new CommandTester($command);
    }
}

<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\AboutCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AboutCommand::class)]
final class AboutCommandTest extends TestCase
{
    #[Test]
    public function it_displays_system_information(): void
    {
        $app = new Application();
        $app->add(new AboutCommand());
        $command = $app->find('about');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Aurora CMS', $output);
        $this->assertStringContainsString('Aurora Version', $output);
        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString(PHP_VERSION, $output);
    }

    #[Test]
    public function it_includes_custom_info(): void
    {
        $app = new Application();
        $app->add(new AboutCommand(['Custom Key' => 'custom-value']));
        $command = $app->find('about');
        $tester = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Custom Key', $output);
        $this->assertStringContainsString('custom-value', $output);
    }
}

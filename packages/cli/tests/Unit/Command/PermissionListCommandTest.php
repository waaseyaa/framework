<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\Access\PermissionHandlerInterface;
use Aurora\CLI\Command\PermissionListCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PermissionListCommand::class)]
final class PermissionListCommandTest extends TestCase
{
    #[Test]
    public function it_lists_permissions_in_a_table(): void
    {
        $handler = $this->createMock(PermissionHandlerInterface::class);
        $handler->method('getPermissions')->willReturn([
            'access content' => [
                'title' => 'Access content',
                'description' => 'View published content',
            ],
            'administer nodes' => [
                'title' => 'Administer nodes',
                'description' => 'Manage all content',
            ],
        ]);

        $tester = $this->createTester($handler);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('access content', $output);
        $this->assertStringContainsString('Access content', $output);
        $this->assertStringContainsString('View published content', $output);
        $this->assertStringContainsString('administer nodes', $output);
    }

    #[Test]
    public function it_shows_message_when_no_permissions(): void
    {
        $handler = $this->createMock(PermissionHandlerInterface::class);
        $handler->method('getPermissions')->willReturn([]);

        $tester = $this->createTester($handler);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('No permissions registered.', $output);
    }

    private function createTester(PermissionHandlerInterface $handler): CommandTester
    {
        $app = new Application();
        $app->add(new PermissionListCommand($handler));
        $command = $app->find('permission:list');

        return new CommandTester($command);
    }
}

<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Make;

use Aurora\CLI\Command\Make\MakeMigrationCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeMigrationCommand::class)]
final class MakeMigrationCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_migration_with_create_table(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'name' => 'create_comments_table',
            '--create' => 'comments',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString("schema->create('comments'", $output);
        $this->assertStringContainsString("schema->dropIfExists('comments')", $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_guesses_table_name_from_migration_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'create_users_table']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString("'users'", $output);
    }

    #[Test]
    public function it_includes_package_info_in_comment(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'name' => 'create_nodes_table',
            '--package' => 'aurora/node',
            '--create' => 'nodes',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('aurora/node', $output);
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->add(new MakeMigrationCommand());
        $command = $app->find('make:migration');

        return new CommandTester($command);
    }
}

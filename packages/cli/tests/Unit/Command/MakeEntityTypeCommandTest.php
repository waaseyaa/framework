<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command;

use Aurora\CLI\Command\MakeEntityTypeCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeEntityTypeCommand::class)]
class MakeEntityTypeCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_config_entity_by_default(): void
    {
        $app = new Application();
        $app->add(new MakeEntityTypeCommand());
        $command = $app->find('make:entity-type');
        $tester = new CommandTester($command);
        $tester->execute(['name' => 'event']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('class Event extends ConfigEntityBase', $output);
        $this->assertStringContainsString('use Aurora\\Entity\\ConfigEntityBase;', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_generates_a_content_entity_with_flag(): void
    {
        $app = new Application();
        $app->add(new MakeEntityTypeCommand());
        $command = $app->find('make:entity-type');
        $tester = new CommandTester($command);
        $tester->execute(['name' => 'article', '--content' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('class Article extends ContentEntityBase', $output);
        $this->assertStringContainsString('use Aurora\\Entity\\ContentEntityBase;', $output);
    }
}

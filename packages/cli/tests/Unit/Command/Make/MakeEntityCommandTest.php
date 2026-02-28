<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Make;

use Aurora\CLI\Command\Make\MakeEntityCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeEntityCommand::class)]
final class MakeEntityCommandTest extends TestCase
{
    #[Test]
    public function it_generates_an_entity_class(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'Article']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('class Article extends ContentEntityBase', $output);
        $this->assertStringContainsString('use Aurora\\Entity\\ContentEntityBase;', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_converts_snake_case_to_pascal_case(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'blog_post']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('class BlogPost extends ContentEntityBase', $output);
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->add(new MakeEntityCommand());
        $command = $app->find('make:entity');

        return new CommandTester($command);
    }
}

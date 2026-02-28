<?php

declare(strict_types=1);

namespace Aurora\CLI\Tests\Unit\Command\Make;

use Aurora\CLI\Command\Make\MakeJobCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(MakeJobCommand::class)]
final class MakeJobCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_job_class(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'ProcessUpload']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('class ProcessUpload extends Job', $output);
        $this->assertStringContainsString('use Aurora\\Queue\\Job\\Job;', $output);
        $this->assertStringContainsString('public function handle(): void', $output);
        $this->assertStringContainsString('declare(strict_types=1);', $output);
    }

    #[Test]
    public function it_converts_snake_case_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['name' => 'process_upload']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('class ProcessUpload extends Job', $output);
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->add(new MakeJobCommand());
        $command = $app->find('make:job');

        return new CommandTester($command);
    }
}

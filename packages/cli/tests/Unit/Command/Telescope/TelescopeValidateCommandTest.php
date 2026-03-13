<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Telescope;

use Waaseyaa\CLI\Command\Telescope\TelescopeValidateCommand;
use Waaseyaa\Telescope\CodifiedContext\Validator\EmbeddingProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(TelescopeValidateCommand::class)]
final class TelescopeValidateCommandTest extends TestCase
{
    private function createEmbeddingProvider(): EmbeddingProviderInterface
    {
        return new class implements EmbeddingProviderInterface {
            public function embed(string $text): array
            {
                return [0.1, 0.2, 0.3];
            }

            public function embedBatch(array $texts): array
            {
                return array_map(fn (string $t) => [0.1, 0.2, 0.3], $texts);
            }

            public function cosineSimilarity(array $a, array $b): float
            {
                return 1.0;
            }
        };
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->add(new TelescopeValidateCommand($this->createEmbeddingProvider()));
        $command = $app->find('telescope:validate');

        return new CommandTester($command);
    }

    #[Test]
    public function it_executes_successfully_with_session_id(): void
    {
        $tester = $this->createTester();
        $tester->execute(['session-id' => 'sess-abc-123']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('sess-abc-123', $output);
        $this->assertStringContainsString('Validator Agent CLI ready', $output);
    }

    #[Test]
    public function it_has_required_session_id_argument(): void
    {
        $command = new TelescopeValidateCommand($this->createEmbeddingProvider());
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('session-id'));
        $argument = $definition->getArgument('session-id');
        $this->assertTrue($argument->isRequired());
    }

    #[Test]
    public function it_has_optional_output_file_argument(): void
    {
        $command = new TelescopeValidateCommand($this->createEmbeddingProvider());
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('output-file'));
        $argument = $definition->getArgument('output-file');
        $this->assertFalse($argument->isRequired());
    }
}
